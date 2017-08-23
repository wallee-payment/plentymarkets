# plentymarkets
Wallee integration for the plentymarkets E-Commerce-ERP. You have the option to install this plugin 
via the git integration inside plentymarkets and just add the URL to this repository
or you installt he plugin via the [plentymarkets marketplace](https://app-wallee.com/en/processors)


# Connect to your Payment Service Provider via wallee
 
With the wallee payment hub you are able to connect to a big selection of payment processors and payment
method through a standardized interface. In other words this plugin will solve all your payment hassel. 
Once the plugin is installed you can easily select the payment processors of your choice and start 
processing payments.  wallee is PCI certified. The connectivity allows you to process payments via credit cards as well as 
any other form of alternative payments. You can also process invoices, 
wallee creates the invoices and you are able to also create your dunning flow and processes. 
An extensive list of all integrated payment processors can be foundon our [payment processor page](https://marketplace.plentymarkets.com/).
 
Your client will be redirected to the wallee payment page at the end of the process. The payment page can be fully styled according your needs.

Beside Processing payments we also solve a lot of other problems for you like:

* Scale with a few clicks and add addional payment providirs or invoice processors
* Creating your indivudualized invoice documents and packing slips
* Automatically print documents via the cloud
* Define your own dunning process for invoices that you process your self
* Automatically send reminder via post with our pingen.com integration

And many more features.


## Getting started and requirements
 
If you want to start using the plugin you will have to make sure that you fulfill the following requirments:

* You do have a wallee account. If not you can [signup](https://app-wallee.com/user/signup) for a test account.
* You do need to install the plugin via marketplace or via github.

 
## Plugin configuration
 
 The plugin configuraiton is easy. Just follow the steps:

* Create an application user and enter the application user id, the secret that is displayed in the backend and the space id.
* Activate the plugin and go to Plugins > Configuration. 
* Activate the payment method you want to accept in your store.

 
### Look and Feel of the payment page, documents and email
 
The plugin will automatically forward the user to the payment page of wallee. This page can be styled 
according your needs. In order to style the payment page, documents or email we use TWIG templates. More information can 
be found on the [documentation](https://app-wallee.com/en/doc/document-handling).
 
### Refunds
 
Set the status ID for Refunds in the configuration. Once the order is moved into that state the refund will be triggered. 
In order to do that please follow the steps below:

1. Create an "Ereignisaktion" for a state change. Give it a name and select the State change that should trigger the action.
2. Select the action and under the folder "Plugin" you should find an action called refund of the wallee payment.
3. Store the configuration.

You have the option between refund and return:

1. Open the order and select the action either create refund or create return.
2. Select the products that you want to return.
3. Move the state of the return into the state selected above for the "Ereignisaktion". This will now automatically synchronize
the items with wallee.


## Further reading

More Information about the features in wallee can be found in our extensive [documentation](https://app-wallee.com/en/doc).
 
## License
 
The plugin ist distributed under the Apache License Version 2.