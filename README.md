# Xendit

Accept credit and debit cards, e-wallets, bank transfers, and send bulk payments via a single integration in Indonesia.

## Install the Gateway

1. upload the source code to a /components/gateways/nonmerchant/xendit/ directory within
your Blesta installation path.

    For example:

    ```
    /var/www/html/blesta/components/gateways/nonmerchant/xendit/
    ```

2. Log in to your admin Blesta account and navigate to
> Settings > Payment Gateways

3. Find the Xendit gateway and click the "Install" button to install it
4. Setting the Invoice Webhook on [Xendit Dashboard](https://dashboard.xendit.co/settings/developers#webhooks) with :
```
<YourBillingDomain>/callback/gw/1/xendit/
```
( if your webserver not support mod_rewrite ) you need to add index.php before /callback.

5. You're done!
