# Connect to your Payment Service Provider via wallee
 
With the wallee payment hub you are able to connect to a big selection of payment processors and payment
method through a standardized interface. In other words this plugin will solve all your payment hassel. 
Once the plugin is installed you can easily select the payment processors of your choice and start 
processing payments.  wallee is PCI certified. The connectivity allows you to process payments via credit cards as well as 
any other form of alternative payments. You can also process invoices, 
wallee creates the invoices and you are able to also create your dunning flow and processes. 
An extensive list of all integrated payment processors can be found on our <a href="https://app-wallee.com/en/processors" target="_blank">payment processor page</a>.
 
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

* You do have a wallee account. If not you can <a href="https://app-wallee.com/user/signup" target="_blank">signup</a> for a test account.
* You do need to install the plugin via marketplace or via github.

 
## Plugin configuration
 
 The plugin configuration is easy. Just follow the steps:

* Create an application user and enter the application user id, the secret that is displayed in the backend and the space id.
* Activate the plugin and go to Plugins > Configuration. 
* Activate the payment method you want to accept in your store.

 
### Look and Feel of the payment page, documents and email
 
The plugin will automatically forward the user to the payment page of wallee. This page can be styled 
according your needs. In order to style the payment page, documents or email we use TWIG templates. More information can 
be found on the <a href="https://app-wallee.com/en/doc/document-handling" target="_blank">documentation</a>.
 
### Refunds
 
Set the status ID for Refunds in the configuration. Once the order is moved into that state the refund will be triggered. 
In order to do that please follow the steps below:

1. Create an "Group Function", in Order > Orders > Group Function,  for a state change. Give it a name and select the State change that should trigger the action.
2. Select the group function and under the folder "Plugin" you should find an action called "Refund" of the wallee payment.
3. Store the configuration.

You have the option between refund and return:

1. Open the order and select the action either "Create refund" or "Create return".
2. Select the items that you want to refund or return.
3. Move the state of the return into the state selected above for the "Group Function". This will now automatically synchronize
the items with wallee.


## Further reading

More Information about the features in wallee can be found in our extensive <a href="https://app-wallee.com/en/doc" target="_blank">documentation</a>.
If you have any questions about the product or the configuration, our <a href="https://en.wallee.com/about-wallee/support?_ga=2.171642464.1523640132.1674037856-1834608674.1611572458" target="_blank">support</a> is also available
 
## License
 
The plugin ist distributed under the Apache License Version 2.