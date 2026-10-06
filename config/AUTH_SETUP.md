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

### Local testing (sandbox)

Use **test** keys. Checkout opens on `dev-flutterwave.com` and no real money moves:

```
FLW_PUBLIC_KEY=FLWPUBK_TEST-...
FLW_SECRET_KEY=FLWSECK_TEST-...
FLW_SECRET_HASH=your-random-webhook-hash
```

### Live site (metrofootball.co.uk)

For **real payments**, use **live** keys on the server (no `TEST` in the key name):

```
APP_ENV=production
FLW_PUBLIC_KEY=FLWPUBK-xxxxxxxx
FLW_SECRET_KEY=FLWSECK-xxxxxxxx
FLW_SECRET_HASH=your-random-webhook-hash
```

After updating `config/.env` on Hostinger, try checkout again.

3. In Flutterwave dashboard → Webhooks, set URL to:

```
https://metrofootball.co.uk/api/billing/webhook
```

## Payment flow

1. Premium signup → OTP → `/link-team` → `/payment` → `/live`
2. Choose monthly (£4) or yearly (£38.50/year, £3.50 per month)
3. **Pay with Flutterwave** opens hosted checkout
4. Callback verifies the transaction, marks Premium active, then opens `/dashboard`

**Nigeria:** When we detect a Nigerian visitor, checkout runs in **NGN** using Flutterwave's live `/v3/rates` endpoint (GBP → NGN). Admin revenue is always stored in GBP. **Live Flutterwave keys are required** for accurate NGN amounts. Test keys return invalid sandbox rates and checkout will show an error until live keys are configured.

## Local flow

1. Join page → Free or Premium → `/register?plan=...`
2. Email signup → Resend OTP → `/verify-otp`
3. After OTP → confirmation email via Resend, then `/link-team` to pick an FPL team, then:
   - Free → `/live`
   - Premium (unpaid) → `/payment` → `/live`
4. Google signup/login skips OTP and uses the same post-auth redirect rules
