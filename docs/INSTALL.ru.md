# Руководство по установке платежного шлюза Novapay в Magento 2

## Загрузка по FTP
1. Загрузите файлы (пример с [FTP-клиентом Filezilla] (https://filezilla-project.org/)) на ваш хостинг (сервер / облако):
    - Подключайтесь к вашему хостингу через FTP или SFTP
    ![Upload files with FTP](images/en/21-FTP-Upload.png)
    - Проверьте расположение модуля на сервере относительно корневого каталога вашего интернет магазина `htdocs/app/code/Novapay/`  
    ![Check files on FTP](images/en/22-FTP-Done.png)
1. Вы можете использовать любой другой доступный метод загрузки, такой как `ssh`, `файловый менеджер панели хостинга`, другие

## Настройка в панели администрирования

### Настройка системы доставки
> Расширение Novapay Доставка зависит от расширения Novapay Оплата и не работает без него.

1. Перейдите в расширения.
    - Нажмите на `Stores` в боковой навигации  
    ![Stores](images/en/02-Admin-Menu-Stores.png)
    - Нажмите на `Configuration` в открытом подменю  
    ![Stores](images/en/03-Admin-Menu-Stores-Configuration.png)
    - Перейдите в `Sales`  
    ![Sales](images/en/04-Admin-Menu-Sales.png)
    - Перейдите в `Delivery methods`  
    ![Delivery methods](images/en/41-Admin-Menu-Delivery-methods.png)
    - Пролистайте до расширения Novapay Доставка.  
    ![Novapay extension](images/en/42-Admin-Novapay-Delivery-settings.png)
1. Настройте расширение
    - Включите расширение Novapay Доставка.  
    ![Enable](images/en/43-Admin-Novapay-Delivery-enabled.png)
    Когда расширение включено другие опции доступны  
    ![Options](images/en/44-Admin-Novapay-Delivery-options.png)
    - Опции:
        - `Title` - заголовок метода доставки
        - `Method Name` — название метода. При оформлении заказа изменяется на `город > отделение` когда выбрано отделение и сумма доставки просчитана
        - `Shipping Cost` — стоимость доставки по умолчанию, до того как выбрано отделение. Установите минимальное значение либо среднее.
        - `Length unit` - единица измерения габаритов.
            - `Width` - дополнительный атрибут, соответствующий длине продукта
            - `Height` - дополнительный атрибут, соответствующий ширине продукта
            - `Depth` - дополнительный атрибут, соответствующий глубине продукта
        - `Weight unit` - единица измерения массы продукта, используется только для дополнительного атрибута. Используйте системное значение чтобы учитывать стандартный атрибут веса продукта.
            - `Weight` - дополнительный атрибут, соответствующий массе продукта. Когда выбрано использовать системное значение данный атрибут не используется.
1. После того как метод доставки настроен и появились заказы с выбранной доставкой в заказах можно видеть трекинговый номер посылки, печатать транспортную накладную и отслеживать посылку.
    - Перейдите в Sales > Orders
    ![Orders](images/en/45-Admin-Novapay-Orders.png)
    - Откройте заказ с доставкой Novapay для просмотра
    ![Order list](images/en/46-Admin-Orders-list.png)
    - Проверьте информацию о доставке ниже секции Payment and Shipment method
    ![Delivery information](images/en/47-Admin-Order-Delivery-info.png)
    - Для доставки заказа необходимо нажать на кнопку `Подтвердить доставку`
    ![Confirm delivery](images/en/48-Admin-Order-Confirm-Delivery.png)
    - После того как доставка подтверждена можно перейти в раздел `Shipments` в боковой навигации заказа
    ![Shipments](images/en/49-Admin-Order-Shipments.png)
    - В этой секции видны все доставки по данному заказу.
    ![Shipments list](images/en/50-Admin-Shipments.png)
    - Когда доставка открыта можно увидеть всю необходимую информацию.
    ![Shipment information](images/en/51-Admin-Shipment-Information.png)
    - Пролистайте вниз до трекинговой информации и кнопки печати.
    ![Tracking information](images/en/52-Admin-Shipping-Tracking.png).
    - Нажмите `Print Shipping Label` чтобы распечатать транспортную накладную
    ![Delivery document](images/en/53-Admin-Shipping-Print.png)
    - Нажмите на ссылку `20400215655683`, всплывающее окно должно открыться, если броузер блокирует открытие всплыващих окон - разрешите для этого сайта
    ![Tracking information](images/en/54-Tracking-information.png)
    - Нажмите на ссылку для отслеживания посылки
    ![Follow my package](images/en/55-Follow-My-Package.png)


### Настройка системы оплаты

1. Войдите в панель администратора на своем интернет магазине, обычно URL-адрес `https://your-webshop-domain.com/admin/`, но он может быть изменен в конфигурации.  
![Административный логин](images/en/01-Admin-Login.png)  
1. Перейдите в Расширения.
    - Нажмите `Stores` на боковой панели навигации  
    ![Stores](images/en/02-Admin-Menu-Stores.png)
    - Нажмите `Configuration` в открытой панели  
    ![Stores](images/en/03-Admin-Menu-Stores-Configuration.png)
    - Перейдите в `Sales`  
    ![Sales](images/en/04-Admin-Menu-Sales.png)
    - Перейдите в `Payment methods`  
    ![Payment methods](images/en/05-Admin-Menu-Payment-methods.png)
    - Прокрутите вниз до платежного расширения Novapay.  
    ![Novapay extension](images/en/06-Admin-Novapay-settings.png)
1. Настройте расширение
    - Включите платежный модуль Novapay  
    ![Enable](images/en/07-Admin-Novapay-enabled.png)  
    Когда он включен, Вы можете видеть подпункты (вкладки) конфигурации  
    ![Tabs](images/en/08-Admin-Novapay-tabs.png)  
    - Полномочия  
    ![Credentials](images/en/09-Admin-Novapay-credentials.png)  
        - `Merchant ID` — идентификатор продавца, предоставляемый Novapay;
        - `Public key` — публичный ключ для запроса postback API;
        - `Private key` — закрытый ключ для запросов API;
        - `Password private key` — пароль к закрытому ключу, используется только в LIVE режиме;
    - Варианты оплаты  
    ![Options](images/en/10-Admin-Novapay-options.png)  
        - `Title` — заголовок, используемый в витрине магазина;
        - `Payment type` — тип платежа DIRECT (прямой) или HOLD (УДЕРЖАНИЕ);
        - `Test mode` — LIVE (рабочий) или TEST (тестовый) режим;
    - Urls  
    ![Urls](images/en/11-Admin-Novapay-redirects.png)  
        - `Success Url` — url успешной страницы после обработки платежа;
        - `Fail Url` — url страницы с ошибкой после обработки платежа;
    - Соответствие статуса  
    ![Status mapping](images/en/12-Admin-Novapay-statuses.png)  
        - `Payment Action Created` — установить состояние заказа при создании платежа;
        - `Payment Action Expired` — установить состояние заказа по истечении срока платежа;
        - `Payment Action Processing` — установить состояние заказа при обработке платежа;
        - `Payment Action Holded` — установить состояние заказа при удержании платежа;
        - `Payment Action Hold confirmed` — установить состояние заказа при подтверждении удержания платежа;
        - `Payment Action Hold completion` — установить состояние заказа при обработке	завершения удержания платежа;
        - `Payment Action Paid` — установить состояние заказа при успешной оплате;
        - `Payment Action Failed` — установить состояние заказа при неудачной оплате;
        - `Payment Action Processing void` — установить состояние заказа при аннулировании платежа;
        - `Payment Action Voided` — установить состояние заказа при аннулировании платежа;

## Тестирование на стороне интернет магазина

### Модуль доставки
1. Перейдите в магазин и добавьте несколько товаров в корзину. Перейдите на страницу оформления заказа и выберите метод доставки `Novapay Delivery` в `Shipping Methods`.  
    ![Select Novapay Delivery](images/en/56-Select-Novapay-Delivery.png)
    > Если такой доставки `Novapay Delivery` нет в списке, какой-то из нижеперечисленных критериев не выполнен:
    > 1. `Novapay Delivery` метод не установлен или не включен
    > 1. Выбранная страна не Украина. Метод `Novapay Delivery` работает только в Украине.
    > 1. Некоторые продукты в корзине не имеют габаритов или массы определённой в магазине. Все продукты должны иметь определённые габариты и настроеные соответствия атрибутов Admin > Sales > Delivery methods > Novapay конфигурация.

1. Введите город получателя и выберите его из выпадающего списка.
1. Введите номер отделения и выберите его из выпадающего списка.
    ![Select Warehouse](images/en/57-Select-Warehouse.png)
1. Проверьте поменялась ли сумма доставки.
1. Переходите к следующему шагу оформления заказа.


### Модуль оплаты
1. Зайдите в свой магазин и добавьте товар в корзину. Перейдите на страницу оформления заказа, выполните шаг `Shipping` и перейдите к `Review & Payments`.  
![Payment method](images/en/31-Front-Reviews-and-Payments.png)  
Вы должны увидеть логотип **Novapay** с радиокнопкой слева. Вы можете нажать на радиокнопку или изображение логотипа.
1. Существуют ограничения для **Country**, **Telephone** и **Currency**. 
    - Вы можете увидеть сообщение об ошибке, если ваш адрес или валюта магазина не соответствуют ограничениям    
    ![Limitations](images/en/32-Front-Limitations.png)  
        - `Country` доступна только **Ukraine**;
        - `Telephone` должен начинаться с **+380**;
        - `Currency` только **UAH** (гривна) и может быть настроено в административной панели.
    - Если все заполнено правильно, вы можете увидеть кнопку **Place Order**  
    ![Payment method](images/en/33-Front-Payment-method.png)  