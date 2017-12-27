<?php

namespace KingFlamez\Rave;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Unirest\Request;
use Unirest\Request\Body;
use Illuminate\Support\Facades\Config;

class Rave
{

    protected $publicKey;
    protected $secretKey;
    protected $amount;
    protected $paymentMethod = 'both';
    protected $customDescription;
    protected $customLogo;
    protected $customTitle;
    protected $country;
    protected $currency;
    protected $customerEmail;
    protected $customerFirstname;
    protected $customerLastname;
    protected $customerPhone;
    protected $txref;
    protected $integrityHash;
    protected $payButtonText = 'Make Payment';
    protected $redirectUrl;
    protected $meta = array();
    protected $env = 'staging';
    protected $transactionPrefix;
    public $logger;
    protected $handler;
    protected $stagingUrl = 'https://rave-api-v2.herokuapp.com';
    protected $liveUrl = 'https://api.ravepay.co';
    protected $baseUrl;
    protected $transactionData;
    protected $overrideTransactionReference;
    protected $requeryCount = 0;


    /**
     * Create a new Rave Instance
     */
    
    /**
     * Construct
     * @param string $prefix This is added to the front of your transaction reference numbers
     * @param boolean $overrideRefWithPrefix Set this parameter to true to use your prefix as the transaction reference
     * @return object
     * */

    public function __construct($prefix=null, $overrideRefWithPrefix = false)
    {
        $this->publicKey = Config::get('rave.publicKey');
        $this->secretKey = Config::get('rave.secretKey');
        $this->env = Config::get('rave.env');
        $this->transactionPrefix = $overrideRefWithPrefix ? $prefix : $prefix.'_';
        $this->overrideTransactionReference = $overrideRefWithPrefix;
        // create a log channel
        $log = new Logger('kingflamez/laravelrave');
        $this->logger = $log;
        $log->pushHandler(new RotatingFileHandler('rave.log', 90, Logger::DEBUG));
        $this->createReferenceNumber();

        if (request()->txref) {
            $txref = request()->txref;
        }
        
        if($this->env === 'staging'){
            $this->baseUrl = $this->stagingUrl;
        }elseif($this->env === 'live'){
            $this->baseUrl = $this->liveUrl;
        }else{
            $this->baseUrl = $this->stagingUrl;
        }
        
        $this->logger->notice('Rave Class Initializes....');
        
        return $this;
    }

    /**
     * Generates a checksum value for the information to be sent to the payment gateway
     * @return object
     * */
    function createCheckSum(){
        $this->logger->notice('Generating Checksum....');

        $country = 'NG';
        switch(request()->currency) {
        case 'KES':
          $country = 'KE';
          break;
        case 'GHS':
          $country = 'GH';
          break;
        default:
          $country = 'NG';
          break;
        }

        $options = array( 
            "PBFPubKey" => Config::get('rave.publicKey'), 
            "amount" => request()->amount, 
            "customer_email" => request()->email, 
            "customer_firstname" => request()->firstname, 
            "txref" => $this->txref, 
            "payment_method" => request()->payment_method, 
            "customer_lastname" => request()->lastname, 
            "country" => $country, 
            "currency" => request()->currency, 
            "custom_description" => request()->description, 
            "custom_logo" => Config::get('rave.logo'), 
            "custom_title" => Config::get('rave.title'), 
            "customer_phone" => request()->phonenumber,
            "pay_button_text" => request()->pay_button_text,
            "redirect_url" => $this->redirectUrl,
            "hosted_payment" => 1
        );

        $options = array_filter($options);
        
        ksort($options);
        
        $this->transactionData = $options;
        
        $hashedPayload = '';
        
        foreach($options as $key => $value){
            $hashedPayload .= $value;
        }
        $completeHash = $hashedPayload.$this->secretKey;
        $hash = hash('sha256', $completeHash);
        
        $this->integrityHash = $hash;
        return $this;
    }
    
    /**
     * Generates a transaction reference number for the transactions
     * @return object
     * */
    function createReferenceNumber(){
        $this->logger->notice('Generating Reference Number....');
        if($this->overrideTransactionReference){
            $this->txref = $this->transactionPrefix;
        }else{
            $this->txref = uniqid($this->transactionPrefix);
        }
        $this->logger->notice('Generated Reference Number....'.$this->txref);
        return $this;
    }
    
    /**
     * gets the current transaction reference number for the transaction
     * @return string
     * */
    function getReferenceNumber(){
        return $this->txref;
    }
    
    /**
     * Sets the transaction meta data. Can be called multiple time to set multiple meta data
     * @param array $meta This are the other information you will like to store with the transaction. It is a key => value array. eg. PNR for airlines, product colour or attributes. Example. array('name' => 'femi')
     * @return object
     * */
    function setMetaData($meta){
        array_push($this->meta, $meta);
        return $this;
    }
    
    /**
     * gets the transaction meta data
     * @return string
     * */
    function getMetaData(){
        return $this->meta;
    }
    
    /**
     * Sets the transaction redirect url
     * @param string $redirectUrl This is where the Rave payment gateway will redirect to after completing a payment
     * @return object
     * */
    function setRedirectUrl($redirectUrl){
        $this->redirectUrl = $redirectUrl;
        return $this;
    }
    
    /**
     * gets the transaction redirect url
     * @return string
     * */
    function getRedirectUrl(){
        return $this->redirectUrl;
    }

    /**
     * Requerys a previous transaction from the Rave payment gateway
     * @param string $referenceNumber This should be the reference number of the transaction you want to requery
     * @return object
     * */
    public function requeryTransaction($referenceNumber){
        $this->txref = $referenceNumber;
        $this->requeryCount++;
        $this->logger->notice('Requerying Transaction....'.$this->txref);

        $data = array(
            'txref' => $this->txref,
            'SECKEY' => Config::get('rave.secretKey'),
            'last_attempt' => '1'
            // 'only_successful' => '1'
        );
        // make request to endpoint using unirest.
        $headers = array('Content-Type' => 'application/json');
        $body = Body::json($data);
        $url = $this->baseUrl.'/flwv3-pug/getpaidx/api/xrequery';
        // Make `POST` request and handle response with unirest
        $response = Request::post($url, $headers, $body);
  
        //check the status is success
        if ($response->body && $response->body->status === "success") {
            if($response->body && $response->body->data && $response->body->data->status === "successful"){
                $this->logger->warn('Requeryed a successful transaction....'.json_encode($response->body->data));
                return $response->body->data;
            }elseif($response->body && $response->body->data && $response->body->data->status === "failed"){
                // Handle Failure
                $this->logger->warn('Requeryed a failed transaction....'.json_encode($response->body->data));
                return $response->body->data;
            }else{
                // Handled an undecisive transaction. Probably timed out.
                $this->logger->warn('Requeryed an undecisive transaction....'.json_encode($response->body->data));
                // I will requery again here. Just incase we have some devs that cannot setup a queue for requery. I don't like this.
                if($this->requeryCount > 4){
                    // Now you have to setup a queue by force. We couldn't get a status in 5 requeries.
                    return false;
                }else{
                    $this->logger->notice('delaying next requery for 3 seconds');
                    sleep(3);
                    $this->logger->notice('Now retrying requery...');
                    $this->requeryTransaction($this->txref);
                }
            }
        }else{
            $this->logger->warn('Requery call returned error for transaction reference.....'.json_encode($response->body).'Transaction Reference: '. $this->txref);
            return json_encode($response->body);
        }
        return false;
    }

    /**
    * Verifies current Transaction from the the Rave Payment Gateway 
    * @param double $amount This should be the total price/amount of the order from your server/database
    * @param string $currency This should be the currency the order was being charged
    * @param boolean $withResults Set true if you need the JSON object
    * @return boolean
    **/

    public function verifyTransfer($amount, $currency, $withResults=false)
    {
            if ($this->requeryTransaction(request()->txref)) {
                // Handle completed payments
                $this->logger->notice('Payment completed. Now verifying payment.');
                
                $ref = request()->flwref;
                $amount = $amount; //Correct Amount from Server
                $currency = $currency; //Correct Currency from Server

                $query = array(
                    "SECKEY" => Config::get('rave.secretKey'),
                    "flw_ref" => $ref,
                    "normalize" => "1"
                );

                $data_string = json_encode($query);
                        
                $ch = curl_init($this->baseUrl.'/flwv3-pug/getpaidx/api/verify');                                                                      
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                              
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

                $response = curl_exec($ch);

                $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $header = substr($response, 0, $header_size);
                $body = substr($response, $header_size);

                curl_close($ch);

                $resp = json_decode($response, true);
                $chargeResponse = $resp['data']['flwMeta']['chargeResponse'];
                $chargeAmount = $resp['data']['amount'];
                $chargeCurrency = $resp['data']['transaction_currency'];
                if (($chargeResponse == "00" || $chargeResponse == "0") && ($chargeAmount == $amount)  && ($chargeCurrency == $currency)){
                   // Handle completed payments
                    $this->logger->notice('Payment completed. Payment Verified.');
                    if ($withResults) {
                        return $resp['data'];
                    }else {
                        return true;
                    }
                }
                elseif(($chargeResponse != "00" && $chargeResponse != "0") && ($chargeAmount == $amount)  && ($chargeCurrency == $currency)){
                    if ($withResults) {
                        return $resp['data'];
                    }else {
                        return false;
                    }
                }
                else {
                    $this->logger->notice('Payment completed. Invalid Payment.');
                    return false;
                }
            } else {
                return false;
            }
            
    }
    
    /**
     * Generates the final json to be used in configuring the payment call to the rave payment gateway
     * @return string
     * */
    
    /**
     * Generates the final json to be used in configuring the payment call to the rave payment gateway
     * @return string
     * */
    function initialize(){

        $this->createCheckSum();
        $this->transactionData = array_merge($this->transactionData, array('integrity_hash' => $this->integrityHash), array('meta' => $this->meta));
        
        if(isset($this->handler)){
            $this->handler->onInit($this->transactionData);
        }
        
        $json = json_encode($this->transactionData);
        echo '<html>';
        echo '<body>';
        echo '<center>Proccessing...<br /><img style="height: 50px;" src="https://media.giphy.com/media/swhRkVYLJDrCE/giphy.gif" /></center>';
        echo '<script type="text/javascript" src="'.$this->baseUrl.'/flwv3-pug/getpaidx/api/flwpbf-inline.js"></script>';
        echo '<script>';
        echo 'document.addEventListener("DOMContentLoaded", function(event) {';
        echo 'var data = JSON.parse(\''.$json.'\');';
        echo 'getpaidSetup(data);';
        echo '});';
        echo '</script>';
        echo '</body>';
        echo '</html>';

    }
    
}
