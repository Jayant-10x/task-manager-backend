<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Password Reset Alert</title>
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
            color: #dc2626;
            margin-top: 0;
            margin-bottom: 16px;
        }
        .text {
            font-size: 14.5px;
            line-height: 1.6;
            color: #475569;
            margin-bottom: 18px;
        }
        .info-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 20px;
            font-size: 13.5px;
        }
        .info-row {
            display: flex;
            margin-bottom: 8px;
        }
        .info-label {
            width: 130px;
            font-weight: 600;
            color: #64748b;
        }
        .info-value {
            color: #0f172a;
            font-weight: 500;
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
        <h2 class="title">Notice: Manager Password Reset Requested</h2>
        <p class="text">
            This is an automated security alert to inform you that a manager has initiated a password reset request.
        </p>
        <div class="info-card">
            <div class="info-row">
                <span class="info-label">Manager Name:</span>
                <span class="info-value">{{ $managerName }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Manager Email:</span>
                <span class="info-value">{{ $managerEmail }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Requested At:</span>
                <span class="info-value">{{ $requestedAt }}</span>
            </div>
            @if(!empty($ipAddress))
            <div class="info-row" style="margin-bottom: 0;">
                <span class="info-label">IP Address:</span>
                <span class="info-value">{{ $ipAddress }}</span>
            </div>
            @endif
        </div>
        <p class="text" style="font-size: 13.5px; color: #475569;">
            A direct, time-limited reset link has been dispatched to the manager's registered email address. This event has also been logged in the system <strong>Activity Log</strong>.
        </p>
        @if(!empty($resetActionUrl))
        <div style="text-align: center; margin: 24px 0;">
            <a href="{{ $resetActionUrl }}" target="_blank" style="display: inline-block; background-color: #2563eb; color: #ffffff !important; font-weight: 600; font-size: 13.5px; padding: 11px 24px; border-radius: 6px; text-decoration: none;">
                Review & Reset in CRM
            </a>
        </div>
        @endif
        <div class="footer">
            <p style="margin: 0;">As an administrator, you may reset the manager's password directly in the CRM, or allow the manager to complete self-service reset via their email link.</p>
        </div>
    </div>
</body>
</html>
