<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Your Password</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #2563eb;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 8px 8px 0 0;
        }
        .content {
            background-color: #f8fafc;
            padding: 30px;
            border-radius: 0 0 8px 8px;
            border: 1px solid #e2e8f0;
        }
        .button {
            display: inline-block;
            background-color: #2563eb;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            margin: 20px 0;
            font-weight: bold;
        }
        .button:hover {
            background-color: #1d4ed8;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            font-size: 14px;
            color: #64748b;
        }
        .warning {
            background-color: #fef3c7;
            border: 1px solid #f59e0b;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Events Platform</h1>
        <h2>Password Reset Request</h2>
    </div>

    <div class="content">
        <p>Hello {{ $userName }},</p>

        <p>We received a request to reset your password for your Events Platform account. If you made this request, click the button below to reset your password:</p>

        <div style="text-align: center;">
            <a href="{{ $resetUrl }}" class="button">Reset My Password</a>
        </div>

        <p>If the button doesn't work, you can copy and paste this link into your browser:</p>
        <p style="word-break: break-all; background-color: #e2e8f0; padding: 10px; border-radius: 4px;">
            {{ $resetUrl }}
        </p>

        <div class="warning">
            <strong>Important:</strong> This password reset link will expire in 60 minutes for security reasons.
        </div>

        <p>If you didn't request a password reset, you can safely ignore this email. Your password will remain unchanged.</p>

        <div class="footer">
            <p>This is an automated email from the Events Platform. Please do not reply to this email.</p>
            <p>If you're having trouble with the reset process, please contact support.</p>
        </div>
    </div>
</body>
</html>