# Auth setup (Resend + Google)

Fill `config/.env` (copy from `config/.env.example` if needed).

## Resend

1. Create an API key at https://resend.com/api-keys
2. Verify your sending domain (or use Resend's onboarding sender while testing)
3. Set:

```
RESEND_API_KEY=re_xxxxxxxx
RESEND_FROM="Metro Football <onboarding@yourdomain.com>"
```

## Google OAuth

1. Open Google Cloud Console → APIs & Services → Credentials
2. Create an OAuth 2.0 Client ID (Web application)
3. Add authorized redirect URI exactly:

```
http://localhost/Metro%20Football%20UK/api/auth/google/callback
```

4. Set in `.env`:

```
GOOGLE_CLIENT_ID=....apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=....
GOOGLE_REDIRECT_URI="http://localhost/Metro%20Football%20UK/api/auth/google/callback"
```

## Flutterwave

1. Open https://dashboard.flutterwave.com/settings/apis
2. Copy your **Public Key**, **Secret Key**, and set a **Secret Hash** for webhooks
3. Put them in `config/.env`:

```
FLW_PUBLIC_KEY=FLWPUBK_TEST-...
FLW_SECRET_KEY=FLWSECK_TEST-...
FLW_SECRET_HASH=your-random-webhook-hash
```

4. In Flutterwave dashboard → Webhooks, set URL to:

```
http://localhost/Metro%20Football%20UK/api/billing/webhook
```

Local tip: use a tunnel (ngrok / Cloudflare Tunnel) so Flutterwave can reach your webhook.

## Payment flow

1. Premium signup → OTP → `/link-team` → `/payment` → `/live`
2. Choose monthly (£2.50) or yearly (£22/year, £2 per month with one month free)
3. **Pay with Flutterwave** opens hosted checkout
4. Callback verifies the transaction, marks Premium active, then opens `/dashboard`

## Local flow

1. Join page → Free or Premium → `/register?plan=...`
2. Email signup → Resend OTP → `/verify-otp`
3. After OTP → confirmation email via Resend, then `/link-team` to pick an FPL team, then:
   - Free → `/live`
   - Premium (unpaid) → `/payment` → `/live`
4. Google signup/login skips OTP and uses the same post-auth redirect rules
