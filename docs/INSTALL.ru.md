# Руководство по установке платежного шлюза Novapay в Magento 2

## Загрузка по FTP
1. Загрузите файлы (пример с [FTP-клиентом Filezilla] (https://filezilla-project.org/)) на ваш хостинг (сервер / облако):
    - Подключайтесь к вашему хостингу через FTP или SFTP
    ![Upload files with FTP](images/en/21-FTP-Upload.png)
    - Проверьте расположение модуля на сервере относительно корневого каталога вашего интернет магазина `htdocs/app/code/Novapay/`  
    ![Check files on FTP](images/en/22-FTP-Done.png)
2. Вы можете использовать любой другой доступный метод загрузки, такой как `ssh`, `файловый менеджер панели хостинга`, другие

## Настройка в панели администрирования

1. Войдите в панель администратора на своем интернет магазине, обычно URL-адрес `https://your-webshop-domain.com/admin/`, но он может быть изменен в конфигурации.  
![Административный логин](images/en/01-Admin-Login.png)  
2. Перейдите в Расширения.
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
3. Настройте расширение
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
1. Зайдите в свой магазин и добавьте товар в корзину. Перейдите на страницу оформления заказа, выполните шаг `Shipping` и перейдите к `Review & Payments`.  
![Payment method](images/en/31-Front-Reviews-and-Payments.png)  
Вы должны увидеть логотип **Novapay** с радиокнопкой слева. Вы можете нажать на радиокнопку или изображение логотипа.
2. Существуют ограничения для **Country**, **Telephone** и **Currency**. 
    - Вы можете увидеть сообщение об ошибке, если ваш адрес или валюта магазина не соответствуют ограничениям    
    ![Limitations](images/en/32-Front-Limitations.png)  
        - `Country` доступна только **Ukraine**;
        - `Telephone` должен начинаться с **+380**;
        - `Currency` только **UAH** (гривна) и может быть настроено в административной панели.
    - Если все заполнено правильно, вы можете увидеть кнопку **Place Order**  
    ![Payment method](images/en/33-Front-Payment-method.png)  