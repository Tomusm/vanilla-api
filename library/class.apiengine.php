<?php if (!defined('APPLICATION')) exit;

/**
 * The main API engine
 *
 * This class handles authentication and delegation of API requests and their
 * corresponding methods.
 *
 * The API engine itself is currently closed off for subclassing. This will
 * hopefully change in the future once the engine is stable and ready for
 * general consumption and extension.
 *
 * @package   API
 * @since     0.1.0
 * @author    Kasper Kronborg Isager <kasperisager@gmail.com>
 * @copyright Copyright 2013 © Kasper Kronborg Isager
 * @license   http://opensource.org/licenses/MIT MIT
 * @final
 */
final class APIEngine
{
    /**
     * Token-based, per-request authentication
     *
     * This function takes the entire request string and turns the query into an
     * array of data. It then uses all the data to generate a signature the same
     * way it got generated on the client. If the server signature and client
     * token match, the client is considered legimate and the request is served.
     *
     * Based on initial work by Diego Zanella
     * @link    http://careers.stackoverflow.com/diegozanella
     *
     * @since  0.1.0
     * @access public
     * @throws Exception
     * @static
     */
    public static function AuthenticateRequest()
    {
        $Request      = Gdn::Request();
        $PathAndQuery = $Request->PathAndQuery();
        $ParsedURL    = parse_url($PathAndQuery);

        // Get the values we need for authentication
        $Username  = GetIncomingValue('username');
        $Email     = GetIncomingValue('email');
        $Timestamp = GetIncomingValue('timestamp');
        $Token     = GetIncomingValue('token');

        // Make sure that the query actually contains data
        if (!isset($ParsedURL['query'])) {
            throw new Exception(T("No authentication query defined"), 401);
        }

        // Now that we're sure the query conatins some data, turn this data into
        // an array which we will later use to analyze each part of the query
        parse_str($ParsedURL['query'], $Request);

        // Unset the client token as we don't want to include it when generating
        // the server signature
        unset($Request['token']);

        // Unset DeliveryType and DeliveryMethod
        unset($Request['DeliveryType']);
        unset($Request['DeliveryMethod']);

        // Make sure that either a username or an email has been passed
        if (empty($Username) && empty($Email)) {
            throw new Exception(T("Authentication required: Username or email must be specified"), 401);
        }

        // Make sure that the query contains a timestamp
        if (empty($Timestamp)) {
            throw new Exception(T("Authentication failed: A timestamp must be specified"), 401);
        }

        // Make sure that this timestamp is still valid
        if ((abs($Timestamp - time())) > C('API.Expiration')) {
            throw new Exception(T("Authentication failed: The request is no longer valid"), 401);
        }

        // Make sure that the query contains a token
        if (empty($Token)) {
            throw new Exception(T("Authentication failed: A token must be specified"), 401);
        }

        // Get the ID of the client (user) sending the request
        $UserID = static::GetUserID($Username, $Email);

        // Throw an error if no user was found
        if (!isset($UserID)) {
            throw new Exception(T("Authentication failed: The specified user doesn't exist"), 401);
        }

        // Generate a signature from the passed data the same way it was
        // generated on the client
        $Signature = static::GenerateSignature($Request);

        // Make sure that the client token and the server signature match
        if ($Token != $Signature) {
            throw new Exception(T("Authentication failed: Token and signature do not match"), 401);
        }

        // Now that we've thoroughly verified the client, start a session for the
        // duration of the request using the User ID we specified earlier
        if ($Token == $Signature) {
            Gdn::Session()->Start(intval($UserID), FALSE);
        }
    }

    /**
     * Generate a signature from a request array
     *
     * This function takes an array of data, sorts the keys alphabetically and
     * generates an HMAC hash using a specified application secret. The hash
     * can then be used to validate incoming API calls as only the client and
     * server knows the secret key used for creating the hash.
     *
     * Based on initial work by Diego Zanella
     * @link http://careers.stackoverflow.com/diegozanella
     *
     * @since  0.1.0
     * @access public
     * @param  array $Request Array of request data uesd for generating the
     *                        signature hash
     * @return string         An HMAC-SHA256 hash generated from the request
     *                        data
     * @static
     */
    public static function GenerateSignature($Request)
    {
        // Get the application secret used for generating the hash
        $Secret = C('API.Secret');

        // Sort the data array alphabetically so we always get the same hash no
        // matter how the data was originally sorted
        ksort($Request, SORT_STRING);

        // Generate a signature by taking all the request data values (we're not
        // interested in the keys), delimiting them with a dash (to avoid hash
        // collisions) and making it all lower case as to ensure consistent hash
        // generation
        $Signature = hash_hmac('sha256', strtolower(implode('-', $Request)), $Secret);

        return $Signature;
    }

    /**
     * Generates a Universally Unique Identifier, version 4
     *
     * @since  0.1.0
     * @access public
     * @link   http://en.wikipedia.org/wiki/UUID
     * @return string A UUID, made up of 32 hex digits and 4 hyphens.
     * @static
     */
    public static function GenerateUniqueID()
    {
        return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            // 32 bits for "time_low"
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),

            // 16 bits for "time_mid"
            mt_rand(0, 0xffff),

            // 16 bits for "time_hi_and_version",
            // four most significant bits holds version number 4
            mt_rand(0, 0x0fff) | 0x4000,

            // 16 bits, 8 bits for "clk_seq_hi_res",
            // 8 bits for "clk_seq_low",
            // two most significant bits holds zero and one for variant DCE1.1
            mt_rand(0, 0x3fff) | 0x8000,

            // 48 bits for "node"
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Get a user ID using either a username or an email
     *
     * Note: if both a username and an email are specified, only the username
     * will be used. This is to prevent abusing of the function by passing two
     * parameters at a time and hoping to get a User ID.
     *
     * Based on initial work by Diego Zanella
     * @link http://careers.stackoverflow.com/diegozanella
     *
     * @since  0.1.0
     * @access public
     * @param  string $Username  Username of the user whose ID we wish to get
     * @param  string $Email     Email of the user whose ID we wish to get
     * @return int|null          User ID if a username or an email has been
     *                           specified, otherwise NULL
     * @static
     */
    public static function GetUserID($Username, $Email)
    {
        // Instantiate a new user model
        $UserModel = new UserModel();

        // Look up the user ID using a username if one has been specified
        if (isset($Username)) {
            return $UserModel->GetByUsername($Username)->UserID;
        }

        // Look up the user ID using an email if one has been specified
        if (isset($Email)) {
            return $UserModel->GetByEmail($Email)->UserID;
        }

        return NULL;
    }

    /**
     * Delegate methods to a specified API class
     *
     * This function takes a request URI and an HTTP method and uses these to
     * delegate an action to the specified API class. In return, the API returns
     * an array of data which we later to map the request to an application or
     * plugin controller.
     *
     * @since  0.1.0
     * @access public
     * @param  Gdn_Request $Request The request object
     * @param  string      $Method  The request method issued by the client
     * @param  object      $Class   The class that we wish to delegate an action to
     * @return array                An array of data returned by the API class
     * @static
     */
    public static function DelegateMethodToClass($Request, $Method, $Class)
    {
        $Path = static::TranslateRequestToPath($Request);

        switch (strtolower($Method)) {
            case 'get':
                $Class->Get($Path);
                $Data = $Class->API;
                break;

            case 'post':
                $Class->Post($Path);
                $Data = $Class->API;

                // Combine the POST request with any custom arguments
                if ($Arguments = val('Arguments', $Data)) {
                    $Merged = array_merge($_POST, $Arguments);
                    $Request->SetRequestArguments(Gdn_Request::INPUT_POST, $Merged);
                }

                $_POST = $Request->Post();

                break;

            case 'put':
                $Class->Put($Path);
                $Data = $Class->API;

                // Garden can't process PUT requests by default, so trick
                // it into thinking that this is actually a POST
                $Request->RequestMethod('post');

                // Parse any form data and store it
                $_PUT = static::ParseFormData();

                // Combine the PUT request with any custom arguments
                if ($Arguments = val('Arguments', $Data)) {
                    $Merged = array_merge($_PUT, $Arguments);
                    $Request->SetRequestArguments(Gdn_Request::INPUT_POST, $Merged);
                } else {
                    $Request->SetRequestArguments(Gdn_Request::INPUT_POST, $_PUT);
                }

                $_POST = $Request->Post();

                break;

            case 'delete':
                $Class->Delete($Path);
                $Data = $Class->API;

                // Garden can't process DELETE requests by default, so trick
                // it into thinking that this is actually a POST
                $Request->RequestMethod('post');

                // Combine the DELETE request with any custom arguments
                if ($Arguments = val('Arguments', $Data)) {
                    $Request->SetRequestArguments(Gdn_Request::INPUT_POST, $Arguments);
                }

                $_POST = $Request->Post();

                break;
        }

        return $Data;
    }

    /**
     * Translate a Request object to a URI path array
     *
     * @since  0.1.0
     * @access public
     * @param  Gdn_Request $Request The request object
     * @return array                The full URI path array
     * @static
     */
    public static function TranslateRequestToPath($Request)
    {
        $URI  = strtolower($Request->RequestURI());
        $Path = explode('/', $URI);

        return $Path;
    }

    /**
     * Map the API request to the appropriate controller
     *
     * @since  0.1.0
     * @access public
     * @param  Gdn_Request $Request The request object
     * @throws Exception
     * @static
     */
    public static function DispatchRequest($Request)
    {
        $Path = static::TranslateRequestToPath($Request);

        // Get the requested resource
        $Resource = val(1, $Path);

        // Turn requested resource into API class and store it
        $Class = ucfirst($Resource) . 'API';

        // Make sure that the requested API class exists
        if (!class_exists($Class)) {
            throw new Exception("No such API found", 404);
        }

        // Instantiate the requested API class
        $Class = new $Class;

        // Make sure that the requested API class extend the API Mapper class
        if (!is_subclass_of($Class, 'APIMapper')) {
            throw new Exception("APIs must be subclassed from the API Mapper", 401);
        }

        // Get the request method issued by the client
        $Method = $Request->RequestMethod();

        // Get data from the requested API class
        $Data = static::DelegateMethodToClass($Request, $Method, $Class);

        // Make sure that the API class returns a controller definition
        if (!$Controller = val('Controller', $Data)) {
            throw new Exception("No controller has been defined", 500);
        }

        // Attempt authentication if no valid session exists
        if (!Gdn::Session()->IsValid()) {
            // If authentication is required, authenticate the client
            if (val('Authenticate', $Data)) {
                static::AuthenticateRequest();
            }
            // If authentication is optional, only authenticate the client if a
            // username or an email has been specified in the request
            else {
                $Username = GetIncomingValue('username');
                $Email    = GetIncomingValue('email');

                if ($Username || $Email) static::AuthenticateRequest();
            }
        }

        // If a method is supplied, set it. Otherwise it's "Index"
        $Method = val('Method', $Data, 'Index');

        // If arguments are supplied, set them. Otherwise they're an empty array
        $Arguments = val('Arguments', $Data, array());

        // If an application is supplied, set it. Otherwise it's false
        $Application = val('Application', $Data, FALSE);

        // Attach the correct application if one has been set
        if ($Application) Gdn_Autoloader::AttachApplication($Application);

        // Map the request to the specified URI
        $Request->WithControllerMethod($Controller, $Method, $Arguments);
    }

    /**
     * Set the header format based on the Request object's HTTP_ACCEPT header
     *
     * @since  1.0.0
     * @access public
     * @param  Gdn_Request $Request The request object
     * @static
     */
    public static function SetHeaders($Request)
    {
        $Arguments = $Request->Export('Arguments');

        // CORS support
        if (C('API.AllowCORS')) {
            $Headers = 'Origin, X-Requested-With, Content-Type, Accept';
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: ' . $Headers);
        }

        // Change response format depending on HTTP_ACCEPT
        $Accept = $Arguments['server']['HTTP_ACCEPT'];
        $Format = (strtolower($Accept) == 'application/xml') ? 'xml' : 'json';

        $Request->WithDeliveryType(DELIVERY_TYPE_DATA);

        switch ($Format) {
            case 'json':
                $Request->WithDeliveryMethod(DELIVERY_METHOD_JSON);
                break;

            case 'xml':
                $Request->WithDeliveryMethod(DELIVERY_METHOD_XML);
                break;
        }
    }

    /**
     * Parse raw Form Data and return it as an array
     *
     * @link http://stackoverflow.com/a/9469615/1293026
     *
     * @since  0.1.0
     * @access public
     * @return array Parsed array of data derived from the raw Form Data
     *               submitted in the request.
     * @static
     */
    public static function ParseFormData()
    {
        // Fetch PUT content and determine Boundary
        $RawData  = file_get_contents('php://input');
        $Boundary = substr($RawData, 0, strpos($RawData, "\r\n"));

        if (empty($Boundary)) {
            parse_str($RawData, $Data);

            return $Data;
        }

        // Fetch each part
        $Parts   = array_slice(explode($Boundary, $RawData), 1);
        $PutData = array();

        foreach ($Parts as $Part) {
            // If this is the last part, break
            if ($Part == "--\r\n") break;

            // Separate content from headers
            $Part = ltrim($Part, "\r\n");
            list($RawHeaders, $PutBody) = explode("\r\n\r\n", $Part, 2);

            // Parse the headers list
            $RawHeaders = explode("\r\n", $RawHeaders);
            $headers    = array();

            foreach ($RawHeaders as $header) {
                list($Name, $Value) = explode(':', $header);
                $headers[strtolower($Name)] = ltrim($Value, ' ');
            }

            // Parse the Content-Disposition to get the field name, etc.
            if (isset($headers['content-disposition'])) {
                $filename = null;
                preg_match(
                    '/^(.+); *name="([^"]+)"(; *filename="([^"]+)")?/',
                    $headers['content-disposition'],
                    $Matches
                );
                list(, $Type, $Name) = $Matches;
                isset($Matches[4]) and $filename = $Matches[4];

                // Handle your fields here
                switch ($Name) {
                    // This is a file upload
                    case 'userfile':
                        file_put_contents($filename, $PutBody);
                        break;

                    // Default for all other files is to populate $PutData
                    default:
                        $PutData[$Name] = substr(
                            $PutBody, 0, strlen($PutBody) - 2
                        );
                        break;
                }
            }
        }

        return $PutData;
    }
}
