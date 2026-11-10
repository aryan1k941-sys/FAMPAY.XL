# FAMPAY.XL — PHP Sandbox Starter

A responsive PHP + SQLite payment-platform demo inspired by the uploaded FamGateway dashboard screenshots.

## Included
- Responsive landing page and merchant login/registration
- Merchant dashboard and transaction list
- Sandbox success/failure transaction simulator
- UPI ID configuration and client-side QR preview (`upi://pay`)
- Demo API key creation (secret shown once; stored as a password hash)
- Payment link preview builder
- Webhook, docs, profile, multi-account placeholder, support and system status pages
- CSRF protection and password hashing
- Dockerfile for Render Web Service deployment

## Important payment limitations
This is a **sandbox/demo starter**, not an authorised payment gateway or Payment Aggregator.
- UPI QR only encodes the merchant-entered UPI ID. It does not verify payment.
- No live money collection, settlement, refunds, bank RRN verification or automatic payment confirmation is implemented.
- The Gmail/IMAP integration shown in the reference screenshot is intentionally not implemented. Email alerts are not authoritative proof of payment and collecting Gmail app passwords in an untrusted app is unsafe.
- For real payments, integrate an authorised payment provider or bank/acquirer API. Verify signed server-to-server webhooks and reconcile with provider/bank records. Follow applicable RBI requirements before operating as a Payment Aggregator.

## Run locally
Requirements: PHP 8.3+, PDO SQLite extension.
```bash
php -S 0.0.0.0:8080
```
Open http://localhost:8080

Default admin (change immediately):
- Email: `admin@fampayxl.local`
- Password: `ChangeMe123!`

Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` environment variables before first launch to customise the admin account. The first admin user is created only if that email does not already exist.

## Deploy on Render (free tier availability/limits can change)
1. Create a GitHub repository and upload the project files.
2. In Render choose **New → Web Service**, connect the repository.
3. Select Docker runtime; Render detects `Dockerfile`.
4. Add environment variables `ADMIN_EMAIL` and `ADMIN_PASSWORD`.
5. Deploy. Open the generated `onrender.com` URL.
6. Note: Render's free filesystem may be ephemeral. SQLite data can be lost on restart/redeploy. For persistent accounts, configure a persistent disk or use a managed PostgreSQL database.

## Production hardening checklist
Use PostgreSQL, HTTPS, secure cookies, rate limiting, login throttling, email verification, backups, secrets management, audit logging, monitoring, security review and a regulated provider integration before handling real payments.
