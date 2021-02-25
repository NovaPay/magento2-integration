# Magento 2 Novapay Payment Gateway Installation Manual

## FTP Upload

1. Upload files (example with [Filezilla FTP client](https://filezilla-project.org/)) onto your hosting (server/cloud):
    - Connect to your hosting via FTP or SFTP  
    ![Upload files with FTP](images/en/21-FTP-Upload.png)
    - Check the server location of the module, relative to your website root directory `htdocs/app/code/Novapay/`  
    ![Check files on FTP](images/en/22-FTP-Done.png)
1. You can use any other available upload method, such as `ssh`, `hosting panel file manager`, etc.

## Administration setup

1. Log in to Admin panel on your website, usually url is `https://your-webshop-domain.com/admin/`, but it might be changed in the configuration.  
![Admin Log in](images/en/01-Admin-Login.png)

### Novapay Delivery extension
> Novapay Delivery extension is dependent on Novapay Payment extension and does not work without it.

1. Go to Extensions.
    - Click on `Stores` in sidebar navigation  
    ![Stores](images/en/02-Admin-Menu-Stores.png)
    - Click on `Configuration` in opened subnavigation  
    ![Stores](images/en/03-Admin-Menu-Stores-Configuration.png)
    - Navigate to `Sales`  
    ![Sales](images/en/04-Admin-Menu-Sales.png)
    - Navigate to `Delivery methods`  
    ![Delivery methods](images/en/41-Admin-Menu-Delivery-methods.png)
    - Scroll down to Novapay delivery extension.  
    ![Novapay extension](images/en/42-Admin-Novapay-Delivery-settings.png)
1. Configure extension
    - Enable Novapay Delivery extension.  
    ![Enable](images/en/43-Admin-Novapay-Delivery-enabled.png)
    When it's enabled you can see other options of the configuration  
    ![Options](images/en/44-Admin-Novapay-Delivery-options.png)
    - Options:
        - `Title` - delivery method title
        - `Method Name` — name of the method. In the checkout when warehouse is selected method name is changed to `city > warehouse`
        - `Shipping Cost` — default shipping cost before system can calculate it (warehouse is not selected). Set minimum or average.
        - `Length unit` - product dimensions unit.
            - `Width` - custom attribute correlated with product package width
            - `Height` - custom attribute correlated with product package height
            - `Depth` - custom attribute correlated with product package width
        - `Weight unit` - product weight unit, works only for a custom attribute. Use system value to calculate a standard weight attribute of the product.
            - `Weight` - custom attribute correlated with product package weight. When system value is selected this value is not used.
1. After delivery method setup you can follow orders with selected delivery methods to check tracking number, print it and track the package delivery.
    - Go to Sales > Orders
    ![Orders](images/en/45-Admin-Novapay-Orders.png)
    - View (click) one order with the Novapay delivery
    ![Order list](images/en/46-Admin-Orders-list.png)
    - Check the delivery information in the order below Payment and Shipment method section
    ![Delivery information](images/en/47-Admin-Order-Delivery-info.png)
    - To proceed with the delivery click link button "Confirm delivery"
    ![Confirm delivery](images/en/48-Admin-Order-Confirm-Delivery.png)
    - After delivery is confirmed click left sidebar navigation "Shipments"
    ![Shipments](images/en/49-Admin-Order-Shipments.png)
    - In this section you can see all the shipments for current order. 
    ![Shipments list](images/en/50-Admin-Shipments.png)
    - When shipment is opened you can see important information.
    ![Shipment information](images/en/51-Admin-Shipment-Information.png)
    - Scroll down to access tracking and printing functionality.
    ![Tracking information](images/en/52-Admin-Shipping-Tracking.png).
    - Click "Print Shipping Label" to print Novaposhta delivery document
    ![Delivery document](images/en/53-Admin-Shipping-Print.png)
    - Click on the delivery number "20400215655683", popup dialog might be created
    ![Tracking information](images/en/54-Tracking-information.png)
    - Click on the link to follow your package in th delivery process
    ![Follow my package](images/en/55-Follow-My-Package.png)


### Novapay Payment extension
1. Go to Extensions.
    - Click on `Stores` in sidebar navigation  
    ![Stores](images/en/02-Admin-Menu-Stores.png)
    - Click on `Configuration` in opened subnavigation  
    ![Stores](images/en/03-Admin-Menu-Stores-Configuration.png)
    - Navigate to `Sales`  
    ![Sales](images/en/04-Admin-Menu-Sales.png)
    - Navigate to `Payment methods`  
    ![Payment methods](images/en/05-Admin-Menu-Payment-methods.png)
    - Scroll down to Novapay payment extension.  
    ![Novapay extension](images/en/06-Admin-Novapay-settings.png)
1. Configure extension
    - Enable Novapay payment extension.  
    ![Enable](images/en/07-Admin-Novapay-enabled.png)
    When it's enabled you can see subitems (tabs) of the configuration  
    ![Tabs](images/en/08-Admin-Novapay-tabs.png)
    - Credentials  
    ![Credentials](images/en/09-Admin-Novapay-credentials.png)
        - `Merchant ID` — merchant id provided by Novapay;
        - `Public key` — public key for postback API request;
        - `Private key` — private key for API requests;
        - `Password private key` — password for private key, used only in LIVE mode;
    - Payment options  
    ![Options](images/en/10-Admin-Novapay-options.png)
        - `Title` — title used in the front store;
        - `Payment type` — DIRECT or HOLD type;
        - `Test mode` — LIVE or TEST mode;
    - Urls  
    ![Urls](images/en/11-Admin-Novapay-redirects.png)
        - `Success Url` — url of the successull page after payment processing;
        - `Fail Url` — url of the failed page after payment processing;
    - Status mapping  
    ![Status mapping](images/en/12-Admin-Novapay-statuses.png)
        - `Payment Action Created` — set the order state when payment created;
        - `Payment Action Expired` — set the order state when payment has expired;
        - `Payment Action Processing` — set the order state when payment is processing;
        - `Payment Action Holded` — set the order state when payment is holded;
        - `Payment Action Hold confirmed` — set the order state when hold payment is confirmed;
        - `Payment Action Hold completion` — set the order state when payment is processing hold;
        - `Payment Action Paid` — set the order state when payment is paid;
        - `Payment Action Failed` — set the order state when payment is failed;
        - `Payment Action Processing void` — set the order state when payment is voiding;
        - `Payment Action Voided` — set the order state when payment is voided;

## Front store test

### Novapay Delivery extension
1. Go to your front store and add some product in the shopping cart. Go to the checkout page and select `Novapay Delivery` in the `Shipping Methods`.  
    ![Select Novapay Delivery](images/en/56-Select-Novapay-Delivery.png)
    > If there is no `Novapay Delivery` some criteria do not meet:
    > 1. `Novapay Delivery` method is not installed or enabled
    > 1. Selected country is not Ukraine. `Novapay Delivery` works only in Ukraine.
    > 1. Some products in your shopping cart have no dimensions or weight defined. All products must have all dimensions (width, height, weight) defined and set up in the Admin > Sales > Delivery methods > Novapay configuration

1. Enter warehouse city and select it from autocomplete dropdown.
1. Enter number of the warehouse and select it from autocomplete dropdown.
    ![Select Warehouse](images/en/57-Select-Warehouse.png)
1. Check the calculated shipping cost.
1. Continue to the next step.

### Novapay Payment extension
1. Go to your front store and add some product in the shopping cart. Go to the checkout page and complete `Shipping` step and go to `Review & Payments`.  
![Payment method](images/en/31-Front-Reviews-and-Payments.png)  
You should see the **Novapay** logo with the radio button on the left. You can click on the radio button or logo image.
1. There are limitations for **Country**, **Telephone** and **Currency**. 
    - You can see the error message if your address or store currency don't fit the limitations  
    ![Limitations](images/en/32-Front-Limitations.png)
        - `Country` is only **Ukraine** available;
        - `Telephone` should start with the **+380**;
        - `Currency` is only **UAH** and can be configured in the store settings by Admin.
    - If everything is filled well you can see **Place Order** button  
    ![Payment method](images/en/33-Front-Payment-method.png)