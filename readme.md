# 阿里云流量监控系统

该项目用于监控阿里云账户的流量使用情况，并在使用率达到95%时，自动发出警报邮件并停止相关实例。项目核心是通过阿里云API获取账户的流量信息，并集成了邮件通知功能。

## 功能介绍

### 1. 配置管理
项目通过读取 `config.json` 文件加载阿里云账户信息和通知的SMTP邮件配置。配置文件的结构如下：

```json
{
  "Accounts": [
    {
      "AccessKeyId": "your-access-key-id",
      "AccessKeySecret": "your-access-key-secret",
      "maxTraffic": 1000,
      "regionId": "cn-hongkong",
      "instanceId": "your-instance-id"
    }
  ],
  "Notification": {
    "email": "your-email@example.com",
    "title": "阿里云流量监控告警",
    "host": "smtp.example.com",
    "username": "your-smtp-username",
    "password": "your-smtp-password",
    "port": 465,
    "secure": "ssl"
  }
}
```

### 2. 自动通知与实例控制
当流量使用率超过95%时，系统会通过以下步骤进行处理：
- **停止实例**：如果流量达到阈值，会自动调用阿里云API停止实例。
- **发送邮件通知**：通过配置发送告警邮件，邮件中包含账户流量详情。

### 3. 支持输出格式
该项目支持两种输出格式：
- **JSON**：适合与其他系统集成，返回账户的流量使用情况。
- **文本格式**：适合终端用户阅读，输出详细的流量使用日志。

## 项目使用

### 安装依赖
使用 Composer 安装项目依赖：
```bash
composer install
```

### 配置文件
修改 `config.json` 填写阿里云账户和邮件通知相关的配置。

### 运行项目
可以通过Web访问或命令行运行项目：
- **Web访问**：在浏览器中访问 `index.php` 并传入 `format=json` 或 `format=text` 参数查看结果。
- **命令行**：通过定时任务运行脚本查看流量信息。

```bash
php index.php
```

## 示例
### JSON格式输出：
```json
[
  {
    "account": "LTAI4G******",
    "flow_total": 1000,
    "flow_used": 950,
    "percentageOfUse": 95,
    "region": "中国香港",
    "rate95": true
  }
]
```

### 文本格式输出：
```text
账号:LTAI4G******
总流量:1000GB
已使用流量:950GB
使用百分比:95%
地区:中国香港
使用率达到95%:是
通知发送:成功
```
