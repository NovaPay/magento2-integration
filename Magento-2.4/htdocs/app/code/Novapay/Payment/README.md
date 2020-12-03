# Novapay Payment module

authorize

## Business Flow

Correct positive flow for Hold payment type (no fraud, void, expire).
1. `created` (Shopping cart > Place order)
2. `processing` (Sent to API)
3. `holded` (Updated by API)
4. `processing_hold_completion` (Confirm hold by admin)
5. `paid` (Updated by API)

Correct positive flow for Direct payment type (no fraud, void, expire).
1. `created` (Shopping cart > Place order)
2. `processing` (Sent to API)
3. `paid` (Updated by API)

## Module flow

### Frontend
1. Validating phone number to start from `+`.
2. If phone number not `+380XXXXXXX` hide payment. 

### Backend
1. Configuration settings.


## Translations
1. To collect all the phrases used in the extension use this command:
```bash
bin/magento-cli i18n:collect-phrases -o app/code/Novapay/Payment/i18n/xx_YY.csv app/code/Novapay/Payment/
```
2. Download `xx_YY.csv` rename to desired `language_COUNTRY.csv`, ukrainian in Ukraine for instance `uk_UA.csv` and translate second column accordingly.
3. Put translated files in `app/code/Novapay/Payment/i18n` directory and upload on server.
4. Change user interface language in `My account`.

## API

### Payment API used

### Delivery API used

1. [Search for a city](https://devcenter.novaposhta.ua/docs/services/556d7ccaa0fe4f08e8f7ce43/operations/556d885da0fe4f08e8f7ce46)
2. [Search for a warehouse](https://devcenter.novaposhta.ua/docs/services/556d7ccaa0fe4f08e8f7ce43/operations/556d8211a0fe4f08e8f7ce45)
3. [Get list of warehouse types](https://devcenter.novaposhta.ua/docs/services/556d7ccaa0fe4f08e8f7ce43/operations/556d8211a0fe4f08e8f7ce45)
4. [Calculate cost of delivery](https://devcenter.novaposhta.ua/docs/services/556eef34a0fe4f02049c664e/operations/55702ee2a0fe4f0cf4fc53ef)
5. [Create a transport document](https://devcenter.novaposhta.ua/docs/services/556eef34a0fe4f02049c664e/operations/56261f14a0fe4f1e503fe187)


### Sessions
- https://qecom.novapay.ua/pay?sid=efb0353b-adb7-4618-a07c-774a02b625f3
- https://qecom.novapay.ua/pay?sid=aca89bfe-9ebb-4118-931d-278525eaeb6d
- https://qecom.novapay.ua/pay?sid=a28b60f6-9412-45a2-abfe-a227454c09be
- https://qecom.novapay.ua/pay?sid=1dfc2c8c-e95b-45a4-87a3-30c5fef8f888
- https://qecom.novapay.ua/pay?sid=f88a70ec-b572-475b-8e9d-264b03323dd9
- https://qecom.novapay.ua/pay?sid=1c0d82e3-cdb0-4993-9355-b700ce766278