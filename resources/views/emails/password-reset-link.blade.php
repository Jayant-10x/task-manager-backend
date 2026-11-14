<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Request</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f4f6f8;
            margin: 0;
            padding: 24px;
            color: #1e293b;
        }
        .container {
            max-width: 540px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: 32px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .header {
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }
        .logo-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .title {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-top: 0;
            margin-bottom: 16px;
        }
        .text {
            font-size: 14.5px;
            line-height: 1.6;
            color: #475569;
            margin-bottom: 24px;
        }
        .btn-container {
            text-align: center;
            margin: 28px 0;
        }
        .btn {
            display: inline-block;
            background-color: #2563eb;
            color: #ffffff !important;
            font-weight: 600;
            font-size: 14px;
            padding: 12px 28px;
            border-radius: 6px;
            text-decoration: none;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        .url-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px;
            font-size: 12px;
            word-break: break-all;
            color: #64748b;
            margin-bottom: 24px;
        }
        .footer {
            border-top: 1px solid #f1f5f9;
            padding-top: 16px;
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="logo-title">10X Agency CRM</h1>
        </div>
        <h2 class="title">Reset Your Password</h2>
        <p class="text">Hello <strong>{{ $userName }}</strong>,</p>
        <p class="text">
            We received a request to reset your password. You can set a new password for your account (<strong>{{ $userEmail }}</strong>) by clicking the button below:
        </p>
        <div class="btn-container">
            <a href="{{ $resetUrl }}" target="_blank" class="btn">Reset Password</a>
        </div>
        <p class="text" style="font-size: 13px; color: #64748b;">
            If the button above does not work, copy and paste this URL into your browser:
        </p>
        <div class="url-box">
            {{ $resetUrl }}
        </div>
        <div class="footer">
            <p style="margin: 0 0 6px;">This link will expire in 2 hours for security reasons.</p>
            <p style="margin: 0;">If you did not request a password reset, you can safely ignore this email. Your password will not change.</p>
        </div>
    </div>
</body>
</html>
