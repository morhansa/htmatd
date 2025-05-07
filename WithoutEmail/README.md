# MagoArab_WithoutEmail

## Overview
MagoArab_WithoutEmail is a Magento 2 extension that replaces the traditional email-based authentication system with a phone number-based system. It integrates with WhatsApp services to provide OTP (One-Time Password) verification for customer authentication.

## Features
- Make phone number the first field in customer registration form and make it visually distinct
- Save phone number as a required field in Magento's customer_entity
- Automatically generate hidden email addresses using the customer's phone number and store domain
- Replace email-based login with phone number login across all customer-related areas
- OTP integration using WhatsApp via 360dialog and UltraMsg services
- Configurable OTP settings (length, expiry time)
- Order status notifications via WhatsApp
- Admin configuration options for phone number validation

## Installation
1. Upload the extension files to the `app/code/MagoArab/WithoutEmail` directory
2. Run the following Magento CLI commands:
## Configuration
1. Go to **Stores > Configuration > MagoArab > WithoutEmail**
2. Configure the general settings:
   - Enable/disable the module
   - Set minimum and maximum phone number length
3. Configure WhatsApp OTP settings:
   - Select WhatsApp provider (UltraMsg or 360Dialog)
   - Enter API credentials
   - Set OTP length and expiry time
4. Configure order notification settings:
   - Enable/disable order status notifications
   - Customize notification templates for different order statuses

## Removed Default Magento Files
This module does not remove any default Magento files, but it overrides certain templates and behaviors:
- Customer registration form template
- Customer login form template
- Customer forgot password form template
- Customer account dashboard template

## How It Works
1. **Registration**: Customer enters phone number and receives an OTP via WhatsApp
2. **Verification**: Customer verifies the phone number with the received OTP
3. **Email Generation**: An email is automatically generated using the phone number and store domain
4. **Login**: Customer can login using their phone number instead of email
5. **Password Reset**: Password reset is done via phone number and OTP verification
6. **Notifications**: Order status updates are sent to the customer via WhatsApp

## Dependencies
- Magento 2.4.x
- PHP 8.1 or higher

## WhatsApp API Providers
This module supports two WhatsApp API providers:
1. **UltraMsg**: https://ultramsg.com
2. **360Dialog**: https://www.360dialog.com

You need to register with one of these providers and obtain API credentials to use the WhatsApp OTP features.

## Support
For any issues or questions, please contact the developer.

## License
[OSL-3.0](https://opensource.org/licenses/OSL-3.0)