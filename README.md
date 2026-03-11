# EYWorks Enquiry Form

A WordPress plugin that replaces the default EYWorks iframe enquiry form with a native, customisable form — complete with EYWorks API integration, Google Tag Manager tracking, local storage, email notifications, and an admin dashboard.

Built for nurseries, pre-schools, and childcare providers using [EYWorks](https://eyworks.co.uk/).

## Why?

The default EYWorks iframe form can't be tracked by Google Tag Manager (cross-origin restrictions), can't be styled to match your site, and doesn't give you a local copy of enquiries. This plugin solves all three.

## Features

- **EYWorks API integration** — submits enquiries directly via the EYWorks `/api/enquiryPost` endpoint
- **Local storage** — every enquiry is saved to your WordPress database, even if EYWorks is down
- **Admin dashboard** — searchable, paginated list of all enquiries with expandable detail view
- **CSV export** — one-click export of all enquiries
- **Email notifications** — sends a plain-text email on every submission
- **GTM dataLayer events** — fires `tour_booking_submitted`, `tour_booking_attempted`, and `tour_booking_error`
- **UTM passthrough** — captures UTM parameters from the page URL and sends them to EYWorks
- **Settings screen** — configure your EYWorks connection from the WordPress admin (no code needed)
- **Resilient** — if the EYWorks API fails, the enquiry is still saved locally and the user sees a success message

## Installation

1. Download or clone this repository into `/wp-content/plugins/eyworks-enquiry-form/`
2. Activate the plugin in **WordPress → Plugins**
3. Go to **Tour Enquiries → Settings** in the WordPress admin
4. Enter your EYWorks subdomain and API token
5. Add the shortcode `[eyworks_enquiry_form]` to any page or post

## Setup

### 1. Find your EYWorks credentials

Log into your EYWorks dashboard and go to **Settings → Access Token → Enquiries**. You'll need:

- **Subdomain** — the part before `.eylog.co.uk` (e.g. if your URL is `mynursery.eylog.co.uk`, your subdomain is `mynursery`)
- **API Token** — the JWT token shown on the Access Token page

### 2. Configure the plugin

Go to **Tour Enquiries → Settings** in your WordPress admin and enter both values. The plugin will test the connection and show a green "Connected" status with your nursery name.

### 3. Add the form

Add this shortcode to any page or post:

```
[eyworks_enquiry_form]
```

The form automatically loads source options (e.g. "Google", "Friend/Family") from your EYWorks account.

Works with Elementor, Gutenberg, WPBakery, or any page builder that supports shortcodes.

## Google Tag Manager

The form fires three `dataLayer` events:

| Event | When | Extra data |
|---|---|---|
| `tour_booking_attempted` | User clicks Submit | `source` |
| `tour_booking_submitted` | Enquiry saved successfully | `source`, `postcode`, `utm_source`, `utm_medium`, `utm_campaign` |
| `tour_booking_error` | Submission failed | `error_message` |

### GTM setup for GA4

1. **Create a trigger:** Custom Event → Event name: `tour_booking_submitted`
2. **Create a GA4 Event tag:** Event name: `tour_booking_submitted`, attach the trigger above
3. Optionally add event parameters: `source`, `postcode`, etc. using Data Layer Variables

### Google Ads conversion tracking

1. Create a **Conversion Linker** tag (fires on all pages)
2. Create a **Google Ads Conversion Tracking** tag triggered by `tour_booking_submitted`

## Admin Dashboard

Go to **Tour Enquiries** in the WordPress admin sidebar to see all submissions. Each row shows:

- Child name, DOB, gender
- Parent name, email, phone
- Source, preferred start date, postcode
- EYWorks sync status (✓ Sent / ✗ Failed)
- UTM parameters (if present)
- Submission timestamp

Click **View** on any row to see the full detail. Use **Export CSV** to download all enquiries.

## Email Notifications

Every submission sends a plain-text email to the configured notification address (defaults to the WordPress admin email). Change this in **Tour Enquiries → Settings**.

## Advanced: wp-config.php overrides

For production environments, you can define settings as constants in `wp-config.php`. These take priority over the Settings page:

```php
define('EYWORKS_API_TOKEN', 'your-token-here');
define('EYWORKS_API_BASE', 'https://yournursery.eylog.co.uk/eyMan/index.php/api');
define('EYWORKS_NOTIFY_EMAIL', 'team@yournursery.co.uk');
```

When constants are defined, the corresponding fields on the Settings page are disabled and show a notice.

## File structure

```
eyworks-enquiry-form/
├── eyworks-enquiry-form.php   # Main plugin file (PHP)
├── eyworks-form.css            # Frontend form styles
├── eyworks-form.js             # Frontend JS (AJAX, validation, GTM)
├── .gitignore
└── README.md
```

## Requirements

- WordPress 5.0+
- PHP 7.4+
- An active EYWorks account with API access enabled

## Changelog

### 2.2.0
- Added Settings screen for configurable EYWorks connection
- Plugin is now generic — any EYWorks nursery can use it
- Connection status indicator on Settings page
- Settings link on Plugins page
- Admin-only error message when form is not yet configured
- wp-config.php constants override Settings page values

### 2.1.0
- Admin dashboard with expandable detail view
- CSV export (clean output, no HTML)
- Preferred start date blocks past dates
- Auto-creates database table without deactivation/reactivation
- Failsafe table check on every submission

### 2.0.0
- Switched to correct EYWorks API (`/api/enquiryPost`)
- Base64-encoded IDs for nursery/source
- UTM parameter passthrough
- Local database storage
- Email notifications

### 1.0.0
- Initial release

## Credits

Built by [Two Ten Studio](https://twotenstudio.co.uk).

## License

GPL-2.0+
