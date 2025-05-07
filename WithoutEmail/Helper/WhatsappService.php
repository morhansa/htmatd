<?php
namespace MagoArab\WithoutEmail\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use MagoArab\WithoutEmail\Helper\Config as ConfigHelper;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Encryption\EncryptorInterface;
use Psr\Log\LoggerInterface;
use MagoArab\WithoutEmail\Model\MessageLogger;

class WhatsappService extends AbstractHelper
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var ConfigHelper
     */
    protected $configHelper;

    /**
     * @var Json
     */
    protected $json;
    
    /**
     * @var EncryptorInterface
     */
    protected $encryptor;
    
    /**
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * @var MessageLogger
     */
    protected $messageLogger;

    /**
     * Constructor
     *
     * @param Context $context
     * @param Curl $curl
     * @param ConfigHelper $configHelper
     * @param Json $json
     * @param EncryptorInterface $encryptor
     * @param LoggerInterface $logger
     * @param MessageLogger $messageLogger
     */
    public function __construct(
        Context $context,
        Curl $curl,
        ConfigHelper $configHelper,
        Json $json,
        EncryptorInterface $encryptor,
        LoggerInterface $logger,
        MessageLogger $messageLogger
    ) {
        parent::__construct($context);
        $this->curl = $curl;
        $this->configHelper = $configHelper;
        $this->json = $json;
        $this->encryptor = $encryptor;
        $this->logger = $logger;
        $this->messageLogger = $messageLogger;
    }

    /**
     * Send OTP via WhatsApp
     *
     * @param string $phoneNumber
     * @param string $otp
     * @param string $messageType
     * @return bool
     * @throws LocalizedException
     */
public function sendOtp(string $phoneNumber, string $otp, string $messageType = 'registration'): bool
{
    if (empty($phoneNumber)) {
        $this->logger->error('Phone number is empty');
        return false;
    }
    
    // Log for debugging
    $this->logger->info('Sending OTP', [
        'phone' => $phoneNumber,
        'otp' => $otp,
        'type' => $messageType
    ]);
    
    $message = $this->getOtpMessageByType($otp, $messageType);
    
    // Make sure the OTP number is in the message
    if (empty($message)) {
        $message = "Your OTP code is: {$otp}";
    }
    
    $provider = $this->configHelper->getWhatsAppProvider();
    
    try {
        switch ($provider) {
                case 'ultramsg':
                    return $this->sendViaUltraMsg($phoneNumber, $message);
                case 'dialog360':
                    return $this->sendViaDialog360($phoneNumber, $message);
                case 'wati':
                    return $this->sendViaWati($phoneNumber, $message);
                case 'twilio':
                    return $this->sendViaTwilio($phoneNumber, $message);
                default:
                    throw new LocalizedException(__('Invalid WhatsApp provider configured.'));
        }
    } catch (\Exception $e) {
        $this->logger->error('WhatsApp Service Error: ' . $e->getMessage());
        return $this->fallbackToNextProvider($provider, $phoneNumber, $message);
    }
}

    /**
     * Send via UltraMsg
     *
     * @param string $phoneNumber
     * @param string $message
     * @return bool
     */
    protected function sendViaUltraMsg(string $phoneNumber, string $message): bool
    {
        $token = $this->configHelper->getUltraMsgApiKey();
        $instance = $this->configHelper->getUltraMsgInstanceId();
        
        if (empty($token) || empty($instance)) {
            $this->logger->error('UltraMsg credentials not configured');
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'ultramsg',
                    $message,
                    'failed',
                    'Credentials not configured'
                );
            }
            
            return false;
        }
        
        try {
            $token = $this->encryptor->decrypt($token);
        } catch (\Exception $e) {
            $this->logger->error('Failed to decrypt UltraMsg token');
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'ultramsg',
                    $message,
                    'failed',
                    'Failed to decrypt token'
                );
            }
            
            return false;
        }
        
        $url = "https://api.ultramsg.com/{$instance}/messages/chat";
        
        // Format phone number
        $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
        if (substr($cleanPhone, 0, 1) !== '+') {
            $cleanPhone = '+' . $cleanPhone;
        }
        
        $params = [
            'token' => $token,
            'to' => $cleanPhone,
            'body' => $message
        ];
        
        try {
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $this->curl->setOption(CURLOPT_SSL_VERIFYHOST, false);
            $this->curl->setOption(CURLOPT_TIMEOUT, 30);
            $this->curl->post($url, $params);
            
            $response = $this->curl->getBody();
            $this->logger->info('UltraMsg Response: ' . $response);
            
            $result = $this->json->unserialize($response);
            
            if (isset($result['sent']) && $result['sent'] === 'true') {
                if (false) { // Disabled monitoring temporarily
                    $this->messageLogger->logMessage(
                        $phoneNumber,
                        'ultramsg',
                        $message,
                        'success',
                        $response
                    );
                }
                return true;
            }
            
            if (isset($result['error'])) {
                $this->logger->error('UltraMsg Error: ' . $result['error']);
            }
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'ultramsg',
                    $message,
                    'failed',
                    $response
                );
            }
            
            return false;
        } catch (\Exception $e) {
            $this->logger->error('UltraMsg Exception: ' . $e->getMessage());
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'ultramsg',
                    $message,
                    'failed',
                    'Exception: ' . $e->getMessage()
                );
            }
            
            return false;
        }
    }

    /**
     * Send via Twilio
     *
     * @param string $phoneNumber
     * @param string $message
     * @return bool
     */
    protected function sendViaTwilio(string $phoneNumber, string $message): bool
    {
        $accountSid = $this->configHelper->getTwilioAccountSid();
        $authToken = $this->configHelper->getTwilioAuthToken();
        $fromNumber = $this->configHelper->getTwilioWhatsappNumber();
        
        if (empty($accountSid) || empty($authToken) || empty($fromNumber)) {
            $this->logger->error('Twilio credentials not configured');
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'twilio',
                    $message,
                    'failed',
                    'Credentials not configured'
                );
            }
            
            return false;
        }
        
        try {
            $authToken = $this->encryptor->decrypt($authToken);
        } catch (\Exception $e) {
            $this->logger->error('Failed to decrypt Twilio auth token');
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'twilio',
                    $message,
                    'failed',
                    'Failed to decrypt auth token'
                );
            }
            
            return false;
        }
        
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
        
        // Format phone number
        $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
        if (substr($cleanPhone, 0, 1) !== '+') {
            $cleanPhone = '+' . $cleanPhone;
        }
        
        $params = [
            'From' => 'whatsapp:' . $fromNumber,
            'To' => 'whatsapp:' . $cleanPhone,
            'Body' => $message
        ];
        
        try {
            $this->curl->setCredentials($accountSid, $authToken);
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $this->curl->post($url, $params);
            
            $response = $this->curl->getBody();
            $this->logger->info('Twilio Response: ' . $response);
            
            $result = $this->json->unserialize($response);
            
            if (isset($result['sid'])) {
                if (false) { // Disabled monitoring temporarily
                    $this->messageLogger->logMessage(
                        $phoneNumber,
                        'twilio',
                        $message,
                        'success',
                        $response
                    );
                }
                return true;
            }
            
            if (isset($result['message'])) {
                $this->logger->error('Twilio Error: ' . $result['message']);
            }
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'twilio',
                    $message,
                    'failed',
                    $response
                );
            }
            
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Twilio Exception: ' . $e->getMessage());
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'twilio',
                    $message,
                    'failed',
                    'Exception: ' . $e->getMessage()
                );
            }
            
            return false;
        }
    }

    /**
     * Send via WATI
     *
     * @param string $phoneNumber
     * @param string $message
     * @return bool
     */
    protected function sendViaWati(string $phoneNumber, string $message): bool
    {
        $apiKey = $this->configHelper->getWatiApiKey();
        $endpoint = $this->configHelper->getWatiEndpoint();
        
        if (empty($apiKey) || empty($endpoint)) {
            $this->logger->error('WATI credentials not configured');
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'wati',
                    $message,
                    'failed',
                    'Credentials not configured'
                );
            }
            
            return false;
        }
        
        try {
            $apiKey = $this->encryptor->decrypt($apiKey);
        } catch (\Exception $e) {
            $this->logger->error('Failed to decrypt WATI API key');
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'wati',
                    $message,
                    'failed',
                    'Failed to decrypt API key'
                );
            }
            
            return false;
        }
        
        $url = $endpoint . '/api/v1/sendSessionMessage/' . preg_replace('/[^0-9]/', '', $phoneNumber);
        
        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json'
        ];
        
        $data = [
            'messageText' => $message
        ];
        
        try {
            $this->curl->setHeaders($headers);
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->post($url, $this->json->serialize($data));
            
            $response = $this->curl->getBody();
            $this->logger->info('WATI Response: ' . $response);
            
            $result = $this->json->unserialize($response);
            
            if (isset($result['result']) && $result['result'] === true) {
                if (false) { // Disabled monitoring temporarily
                    $this->messageLogger->logMessage(
                        $phoneNumber,
                        'wati',
                        $message,
                        'success',
                        $response
                    );
                }
                return true;
            }
            
            if (isset($result['message'])) {
                $this->logger->error('WATI Error: ' . $result['message']);
            }
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'wati',
                    $message,
                    'failed',
                    $response
                );
            }
            
            return false;
        } catch (\Exception $e) {
            $this->logger->error('WATI Exception: ' . $e->getMessage());
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'wati',
                    $message,
                    'failed',
                    'Exception: ' . $e->getMessage()
                );
            }
            
            return false;
        }
    }

    /**
     * Send via Dialog360
     *
     * @param string $phoneNumber
     * @param string $message
     * @return bool
     */
    protected function sendViaDialog360(string $phoneNumber, string $message): bool
    {
        $apiKey = $this->configHelper->getDialog360ApiKey();
        
        if (empty($apiKey)) {
            $this->logger->error('Dialog360 API key not configured');
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'dialog360',
                    $message,
                    'failed',
                    'API key not configured'
                );
            }
            
            return false;
        }
        
        try {
            $apiKey = $this->encryptor->decrypt($apiKey);
        } catch (\Exception $e) {
            $this->logger->error('Failed to decrypt Dialog360 API key');
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'dialog360',
                    $message,
                    'failed',
                    'Failed to decrypt API key'
                );
            }
            
            return false;
        }
        
        $url = "https://waba.360dialog.io/v1/messages";
        
        $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        $data = [
            'to' => $cleanPhone,
            'type' => 'text',
            'text' => [
                'body' => $message
            ]
        ];
        
        $headers = [
            'D360-API-KEY' => $apiKey,
            'Content-Type' => 'application/json'
        ];
        
        try {
            $this->curl->setHeaders($headers);
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->post($url, $this->json->serialize($data));
            
            $response = $this->curl->getBody();
            $this->logger->info('Dialog360 Response: ' . $response);
            
            $result = $this->json->unserialize($response);
            
            if (isset($result['messages'][0]['id'])) {
                if (false) { // Disabled monitoring temporarily
                    $this->messageLogger->logMessage(
                        $phoneNumber,
                        'dialog360',
                        $message,
                        'success',
                        $response
                    );
                }
                return true;
            }
            
            if (isset($result['errors'])) {
                $this->logger->error('Dialog360 Error: ' . $this->json->serialize($result['errors']));
            }
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'dialog360',
                    $message,
                    'failed',
                    $response
                );
            }
            
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Dialog360 Exception: ' . $e->getMessage());
            
            if (false) { // Disabled monitoring temporarily
                $this->messageLogger->logMessage(
                    $phoneNumber,
                    'dialog360',
                    $message,
                    'failed',
                    'Exception: ' . $e->getMessage()
                );
            }
            
            return false;
        }
    }

    /**
     * Fallback to next available provider
     *
     * @param string $currentProvider
     * @param string $phoneNumber
     * @param string $message
     * @return bool
     */
    protected function fallbackToNextProvider(string $currentProvider, string $phoneNumber, string $message): bool
    {
        $providers = ['ultramsg', 'dialog360', 'wati', 'twilio'];
        $currentIndex = array_search($currentProvider, $providers);
        
        if ($currentIndex !== false && $currentIndex < count($providers) - 1) {
            $nextProvider = $providers[$currentIndex + 1];
            $this->logger->info("Falling back from {$currentProvider} to {$nextProvider}");
            
            switch ($nextProvider) {
                case 'ultramsg':
                    return $this->sendViaUltraMsg($phoneNumber, $message);
                case 'dialog360':
                    return $this->sendViaDialog360($phoneNumber, $message);
                case 'wati':
                    return $this->sendViaWati($phoneNumber, $message);
                case 'twilio':
                    return $this->sendViaTwilio($phoneNumber, $message);
            }
        }
        
        return false;
    }

    /**
     * Generate OTP code
     *
     * @return string
     */
    public function generateOtp(): string
    {
        $otpLength = $this->configHelper->getOtpLength();
        $characters = '0123456789';
        $otp = '';
        
        for ($i = 0; $i < $otpLength; $i++) {
            $otp .= $characters[rand(0, strlen($characters) - 1)];
        }
        
        return $otp;
    }

    /**
     * Get OTP message by type
     *
     * @param string $otp
     * @param string $messageType
     * @return string
     */
    protected function getOtpMessageByType(string $otp, string $messageType): string
    {
        switch ($messageType) {
            case 'registration':
                return __('Your registration OTP code is: %1. This code will expire in %2 minutes.', 
                    $otp, 
                    $this->configHelper->getOtpExpiry()
                );
            case 'forgot_password':
                return __('Your password reset OTP code is: %1. This code will expire in %2 minutes.', 
                    $otp, 
                    $this->configHelper->getOtpExpiry()
                );
            case 'change_phone':
                return __('Your phone number change OTP code is: %1. This code will expire in %2 minutes.', 
                    $otp, 
                    $this->configHelper->getOtpExpiry()
                );
            default:
                return __('Your OTP code is: %1. This code will expire in %2 minutes.', 
                    $otp, 
                    $this->configHelper->getOtpExpiry()
                );
        }
    }

    /**
     * Send order status notification
     *
     * @param string $phoneNumber
     * @param array $params
     * @param string $status
     * @return bool
     */
public function sendOrderStatusNotification(string $phoneNumber, array $params, string $status): bool
{
    $message = $this->getOrderStatusMessage($params, $status);
    $provider = $this->configHelper->getWhatsAppProvider();
    
    try {
        switch ($provider) {
            case 'ultramsg':
                return $this->sendViaUltraMsg($phoneNumber, $message);
            case 'dialog360':
                return $this->sendViaDialog360($phoneNumber, $message);
            case 'wati':
                return $this->sendViaWati($phoneNumber, $message);
            case 'twilio':
                return $this->sendViaTwilio($phoneNumber, $message);
            default:
                throw new LocalizedException(__('Invalid WhatsApp provider configured.'));
        }
    } catch (\Exception $e) {
        $this->logger->error('WhatsApp Service Error: ' . $e->getMessage());
        return $this->fallbackToNextProvider($provider, $phoneNumber, $message);
    }
}

    /**
     * Get order status message
     *
     * @param array $params
     * @param string $status
     * @return string
     */
 protected function getOrderStatusMessage(array $params, string $status): string
{
    // Use templates from config
    $template = $this->configHelper->getTemplateForStatus($status);
    
    if (empty($template)) {
        $template = $this->getDefaultTemplate($status);
    }
    
    // Replace placeholders
    $placeholders = [
        '{{order_id}}' => $params['order_id'] ?? '',
        '{{customer_name}}' => $params['customer_name'] ?? '',
        '{{order_total}}' => $params['order_total'] ?? '',
        '{{tracking_number}}' => $params['tracking_number'] ?? '',
        '{{business_name}}' => $this->configHelper->getBusinessName(),
        '{{support_phone}}' => $this->configHelper->getSupportPhone(),
        '{{order_date}}' => $params['order_date'] ?? '',
        '{{delivery_date}}' => $params['delivery_date'] ?? '',
        '{{payment_method}}' => $params['payment_method'] ?? '',
        '{{shipping_method}}' => $params['shipping_method'] ?? '',
        '{{order_status}}' => ucfirst($status),
        '{{order_link}}' => $params['order_link'] ?? ''
    ];
    
    $message = $template;
    foreach ($placeholders as $placeholder => $value) {
        $message = str_replace($placeholder, $value, $message);
    }
    
    return $message;
}
/**
 * Format and validate phone number
 *
 * @param string $phoneNumber
 * @return string|false
 */
public function formatPhoneNumber(string $phoneNumber)
{
    // Remove any non-digit characters except + at the beginning
    $cleaned = preg_replace('/[^\d+]/', '', $phoneNumber);
    if (substr($cleaned, 0, 1) === '+') {
        $cleaned = '+' . preg_replace('/\D/', '', substr($cleaned, 1));
    } else {
        $cleaned = preg_replace('/\D/', '', $cleaned);
    }
    
    // Check if it meets minimum length requirements
    $minLength = $this->configHelper->getMinPhoneLength();
    $maxLength = $this->configHelper->getMaxPhoneLength();
    
    // Count without + for length check
    $digitsOnly = preg_replace('/\D/', '', $cleaned);
    if (strlen($digitsOnly) < $minLength || strlen($digitsOnly) > $maxLength) {
        $this->_logger->warning('Invalid phone number length: ' . $cleaned);
        return false;
    }
    
    // Format according to config
    $format = $this->configHelper->getPhoneFormat();
    if ($format === 'international' && substr($cleaned, 0, 1) !== '+') {
        $cleaned = '+' . $cleaned;
    } elseif ($format === 'local') {
        $cleaned = preg_replace('/\D/', '', $cleaned);
    }
    
    return $cleaned;
}
    /**
     * Get default template
     *
     * @param string $status
     * @return string
     */
    protected function getDefaultTemplate(string $status): string
    {
        $templates = [
            'pending' => 'Hello {{customer_name}}, your order #{{order_id}} has been received.',
            'processing' => 'Hello {{customer_name}}, your order #{{order_id}} is being processed.',
            'complete' => 'Hello {{customer_name}}, your order #{{order_id}} has been completed.',
            'canceled' => 'Hello {{customer_name}}, your order #{{order_id}} has been canceled.',
            'holded' => 'Hello {{customer_name}}, your order #{{order_id}} is on hold.',
            'shipped' => 'Hello {{customer_name}}, your order #{{order_id}} has been shipped!',
            'refunded' => 'Hello {{customer_name}}, your order #{{order_id}} has been refunded.'
        ];
        
        return $templates[$status] ?? 'Order #{{order_id}} status: {{order_status}}';
    }
}