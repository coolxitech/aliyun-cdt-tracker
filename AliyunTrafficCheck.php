<?php

require 'vendor/autoload.php';

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;
use PHPMailer\PHPMailer\PHPMailer;
class AliyunTrafficCheck
{
    protected array $config;
    private mixed $accounts;
    private mixed $notificationEmail;
    private mixed $notificationTitle;
    private mixed $notificationHost;
    private mixed $notificationUsername;
    private mixed $notificationPassword;
    private mixed $notificationPort;
    private mixed $notificationSecure;

    public function __construct()
    {
        $this->config = json_decode(file_get_contents('config.json'), true);
        $this->accounts = $this->config['Accounts'];
        $this->notificationEmail = $this->config['Notification']['email'];
        $this->notificationTitle = $this->config['Notification']['title'];
        $this->notificationHost = $this->config['Notification']['host'];
        $this->notificationUsername = $this->config['Notification']['username'];
        $this->notificationPassword = $this->config['Notification']['password'];
        $this->notificationPort = $this->config['Notification']['port'];
        $this->notificationSecure = $this->config['Notification']['secure'];
    }

    public function getTraffic($accessKeyId, $accessKeySecret)
    {
        try {
            AlibabaCloud::accessKeyClient($accessKeyId, $accessKeySecret)
                ->regionId('cn-hongkong')
                ->asDefaultClient();

            $result = AlibabaCloud::rpc()
                ->product('CDT')
                ->version('2021-08-13')
                ->action('ListCdtInternetTraffic')
                ->method('POST')
                ->host('cdt.aliyuncs.com')
                ->request();

            $total = array_sum(array_column($result['TrafficDetails'], 'Traffic'));
            return $total / (1024 * 1024 * 1024); // Convert to GB
        } catch (ClientException $e) {
            echo '客户端异常: ' . $e->getErrorMessage() . PHP_EOL;
        } catch (ServerException $e) {
            echo '服务器异常: ' . $e->getErrorMessage() . PHP_EOL;
        }
        return 0;
    }

    public function check($format = 'json'): string|array
    {
        if ($format == 'json') {
            $logs = [];
        } elseif ($format == 'text') {
            $logs = '';
        }

        foreach ($this->accounts as $account) {
            $traffic = $this->getTraffic($account['AccessKeyId'], $account['AccessKeySecret']);
            $maskedAccessKeyId = substr($account['AccessKeyId'], 0, 7) . '***';
            $usagePercentage = round(($traffic / $account['maxTraffic']) * 100, 2);
            $regionName = $this->getRegionName($account['regionId']);
            $isFull = $usagePercentage >= 95 ? '是' : '否';


            if ($usagePercentage >= 95) {
                $this->controlInstance($account, 'stop'); // 发送停止实例请求
                $notificationResult = $this->sendNotification($account['AccessKeyId'], $traffic);
                $notificationStatus = $notificationResult === true ? '成功' : "失败: $notificationResult";
            } else {
                $notificationStatus = '不需要';
            }
            if ($format == 'json') {
                $log = [
                    'account' => $maskedAccessKeyId,
                    'flow_total' => $account['maxTraffic'],
                    'flow_used' => $traffic,
                    'percentageOfUse' => $usagePercentage,
                    'region' => $account['regionId'],
                    'rate95' => $usagePercentage >= 95,
                    'sendNotification' => $notificationStatus
                ];
                $logs[] = $log;
            } elseif ($format == 'text') {
                $log = <<< EOF
            账号:$maskedAccessKeyId
            总流量:{$account['maxTraffic']}GB
            已使用流量{$traffic}GB
            使用百分比:$usagePercentage%
            地区:$regionName
            使用率达到95%:$isFull
            通知发送:$notificationStatus
            EOF;
                $log = $log . PHP_EOL . PHP_EOL;
                $logs .= $log;
            }

        }
        if ($format == 'json') {
            return json_encode($logs, JSON_UNESCAPED_UNICODE);
        } elseif ($format == 'text') {
            $logs .= date('Y-m-d H:i:s').PHP_EOL;
            return $logs;
        } else {
            return $this->renderPhpFile('template.html');
        }

    }

    private function sendNotification($accessKeyId, $traffic)
    {
        $emailConfig = $this->config['Notification'];

        $message = "账号: {$accessKeyId}<br>流量: {$traffic}GB<br>已达到 95% 使用率";

        return $this->send_mail(
            $this->notificationEmail,
            '',
            $this->notificationTitle,
            $message,
            null,
            $emailConfig
        );
    }

    private function send_mail($to, $name, $subject = '', $body = '', $attachment = null, $config = '')
    {
        $config = is_array($config) ? $config : array();
        $mail = new PHPMailer();
        $mail->CharSet = 'UTF-8';
        $mail->IsSMTP();
        $mail->SMTPDebug = 0;
        $mail->SMTPAuth = true;
        $mail->SMTPSecure = $this->notificationSecure;
        $mail->Host = $this->notificationHost;
        $mail->Port = $this->notificationPort;
        $mail->Username = $this->notificationUsername;
        $mail->Password = $this->notificationPassword;
        $mail->SetFrom($this->notificationUsername, '阿里云CDT告警');
        $mail->Subject = $subject;
        $mail->MsgHTML($body);
        $mail->AddAddress($to, $name);
        return $mail->Send() ? true : $mail->ErrorInfo;
    }

    public function performAction($account, $action)
    {
        try {
            AlibabaCloud::accessKeyClient($account['AccessKeyId'], $account['AccessKeySecret'])
                ->regionId($account['regionId'])
                ->asDefaultClient();

            $result = AlibabaCloud::rpc()
                ->product('Ecs')
                ->version('2014-05-26')
                ->action('DescribeInstanceStatus')
                ->method('POST')
                ->host("ecs.{$account['regionId']}.aliyuncs.com")
                ->options([
                    'query' => [
                        'RegionId' => $account['regionId'],
                        'InstanceId' => $account['instanceId']
                    ]
                ])
                ->request();

            $instanceStatus = $result['InstanceStatuses']['InstanceStatus'][0]['Status'];

            if ($action === 'stop' && $instanceStatus !== 'Stopped') {
                AlibabaCloud::rpc()
                    ->product('Ecs')
                    ->version('2014-05-26')
                    ->action('StopInstance')
                    ->method('POST')
                    ->host("ecs.{$account['regionId']}.aliyuncs.com")
                    ->options([
                        'query' => [
                            'RegionId' => $account['regionId'],
                            'InstanceId' => $account['instanceId']
                        ]
                    ])
                    ->request();
            } elseif ($action === 'start' && $instanceStatus !== 'Running') {
                AlibabaCloud::rpc()
                    ->product('Ecs')
                    ->version('2014-05-26')
                    ->action('StartInstance')
                    ->method('POST')
                    ->host("ecs.{$account['regionId']}.aliyuncs.com")
                    ->options([
                        'query' => [
                            'RegionId' => $account['regionId'],
                            'InstanceId' => $account['instanceId']
                        ]
                    ])
                    ->request();
            }
        } catch (ClientException $e) {
            echo '客户端异常: ' . $e->getErrorMessage() . PHP_EOL;
        } catch (ServerException $e) {
            echo '服务器异常: ' . $e->getErrorMessage() . PHP_EOL;
        }
    }

    public function controlInstance($account, $action)
    {
        try {
            AlibabaCloud::accessKeyClient($account['AccessKeyId'], $account['AccessKeySecret'])
                ->regionId($account['regionId'])
                ->asDefaultClient();

            if (!empty($account['instanceId'])) {
                $this->performAction($account, $action);
            } else {
                $result = AlibabaCloud::rpc()
                    ->product('Ecs')
                    ->version('2014-05-26')
                    ->action('DescribeInstances')
                    ->method('POST')
                    ->host("ecs.{$account['regionId']}.aliyuncs.com")
                    ->options([
                        'query' => [
                            'RegionId' => $account['regionId']
                        ]
                    ])
                    ->request();

                foreach ($result['Instances']['Instance'] as $instance) {
                    $account['instanceId'] = $instance['InstanceId'];
                    $this->performAction($account, $action);
                }
            }
        } catch (ClientException $e) {
            echo '客户端异常: ' . $e->getErrorMessage() . PHP_EOL;
        } catch (ServerException $e) {
            echo '服务器异常: ' . $e->getErrorMessage() . PHP_EOL;
        }
    }

    private function getRegionName($regionId)
    {
        $regions = [
            'cn-qingdao' => '华北1(青岛)',
            'cn-beijing' => '华北2(北京)',
            'cn-zhangjiakou' => '华北3(张家口)',
            'cn-huhehaote' => '华北5(呼和浩特)',
            'cn-wulanchabu' => '华北6(乌兰察布)',
            'cn-hangzhou' => '华东1(杭州)',
            'cn-shanghai' => '华东2(上海)',
            'cn-nanjing' => '华东5 (南京-本地地域)',
            'cn-fuzhou' => '华东6(福州-本地地域)',
            'cn-wuhan-lr' => '华中1(武汉-本地地域)',
            'cn-shenzhen' => '华南1(深圳)',
            'cn-heyuan' => '华南2(河源)',
            'cn-guangzhou' => '华南3(广州)',
            'cn-chengdu' => '西南1(成都)',
            'cn-hongkong' => '中国香港',
            'ap-southeast-1' => '新加坡',
            'ap-southeast-2' => '澳大利亚(悉尼)',
            'ap-southeast-3' => '马来西亚(吉隆坡)',
            'ap-southeast-5' => '印度尼西亚(雅加达)',
            'ap-southeast-6' => '菲律宾(马尼拉)',
            'ap-southeast-7' => '泰国(曼谷)',
            'ap-northeast-1' => '日本(东京)',
            'ap-northeast-2' => '韩国(首尔)',
            'us-west-1' => '美国(硅谷)',
            'us-east-1' => '美国(弗吉尼亚)',
            'eu-central-1' => '德国(法兰克福)',
            'eu-west-1' => '英国(伦敦)',
            'me-east-1' => '阿联酋(迪拜)',
            'me-central-1' => '沙特(利雅得)'
        ];

        return $regions[$regionId] ?? '未知地区';
    }

    private function renderPhpFile($filePath, $variables = [])
    {
        // 确保文件存在
        if (!file_exists($filePath)) {
            return "文件不存在";
        }

        // 将变量导入当前符号表，以便可以在PHP文件中使用
        extract($variables);

        // 开启输出缓冲
        ob_start();

        // 包含PHP文件，此时该文件的输出将被缓冲而不是直接输出
        include $filePath;

        // 获取缓冲区的内容并清除缓冲
        $content = ob_get_clean();

        // 返回渲染后的内容
        return $content;
    }
}