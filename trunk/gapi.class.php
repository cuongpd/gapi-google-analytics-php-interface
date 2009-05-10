<?php
/**
 * GAPI - Google Analytics PHP Interface
 * 
 * http://code.google.com/p/gapi-google-analytics-php-interface/
 * 
 * @copyright Stig Manning 2009
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * @author Stig Manning <stig@sdm.co.nz>
 * @version 0.2 beta
 * 
 */

class gapi
{
  const client_login_url = 'https://www.google.com/accounts/ClientLogin';
  const account_data_url = 'https://www.google.com/analytics/feeds/accounts/default';
  const report_data_url = 'https://www.google.com/analytics/feeds/data';
  
  const interface_name = 'GAPI-0.1beta';
  
  private $auth_token = null;
  
  /**
   * Constructor function for all new gapi instances
   * 
   * Set up authenticate with Google and get auth_token
   *
   * @param String $email
   * @param String $password
   * @param String $token
   * @return gapi
   */
  public function __construct($email, $password, $token=null)
  {
    if($token !== null)
    {
      $this->auth_token = $token;
    }
    else 
    {
      $this->authenticateUser($email,$password);
    }
  }
  
  /**
   * Return the auth token, used for storing the auth token in the user session
   *
   * @return String
   */
  public function getAuthToken()
  {
    return $this->auth_token;
  }
  
  /**
   * Request account data from Google Analytics
   * 
   * $parameters should be in key => value format
   *
   * @param Array $parameters
   */
  public function requestAccountData($parameters=null)
  {
    $response = $this->httpRequest(gapi::account_data_url, $parameters, null, $this->generateAuthHeader());
    
    if(substr($response['code'],0,1) == '2')
    {
      return $response['body'];
    }
    else 
    {
      throw new Exception('GAPI: Failed to request account data. Error: "' . $response['body'] . '"');
    }
  }
  
  /**
   * Request report data from Google Analytics
   *
   * $report_id is the Google report ID for the selected account
   * 
   * $parameters should be in key => value format
   * 
   * @param String $report_id
   * @param Array $dimensions Google Analytics dimensions e.g. array('browser')
   * @param Array $metrics Google Analytics metrics e.g. array('pageviews')
   * @param Array $sort_metric OPTIONAL: Dimension or dimensions to sort by
   * @param String $start_date OPTIONAL: Start of reporting period
   * @param String $end_date OPTIONAL: End of reporting period
   * @param Int $start_index OPTIONAL: Start index of results
   * @param Int $max_results OPTIONAL: Max results returned
   */
  public function requestReportData($report_id, $dimensions, $metrics, $sort_metric=null, $start_date=null, $end_date=null, $start_index=1, $max_results=30)
  {
    $parameters = array('ids'=>'ga:' . $report_id);
    
    if(is_array($dimensions))
    {
      $dimensions_string = '';
      foreach($dimensions as $dimesion)
      {
        $dimensions_string .= ',ga:' . $dimesion;
      }
      $parameters['dimensions'] = substr($dimensions_string,1);
    }
    else 
    {
      $parameters['dimensions'] = 'ga:'.$dimensions;
    }

    if(is_array($metrics))
    {
      $metrics_string = '';
      foreach($metrics as $metric)
      {
        $metrics_string .= ',ga:' . $metric;
      }
      $parameters['metrics'] = substr($metrics_string,1);
    }
    else 
    {
      $parameters['metrics'] = 'ga:'.$metrics;
    }
    
    if($sort_metric==null)
    {
      $parameters['sort'] = substr($metrics_string,1);
    }
    elseif(is_array($sort_metric))
    {
      $sort_metric_string = '';
      
      foreach($sort_metric as $sort_metric_value)
      {
        $sort_metric_string .= ',ga:' . $sort_metric_value;
      }
      
      $parameters['sort'] = $sort_metric_string;
    }
    else 
    {
      $parameters['sort'] = $sort_metric;
    }
    
    if($start_date==null)
    {
      $start_date=date('Y-m-d',strtotime('1 month ago'));
    }
    
    $parameters['start-date'] = $start_date;
    
    if($end_date==null)
    {
      $end_date=date('Y-m-d');
    }
    
    $parameters['end-date'] = $end_date;
    
    $response = $this->httpRequest(gapi::report_data_url, $parameters, null, $this->generateAuthHeader());
    
    //HTTP 2xx
    if(substr($response['code'],0,1) == '2')
    {
      return $this->objectMapper($response['body']);
    }
    else 
    {
      throw new Exception('GAPI: Failed to request report data. Error: "' . $response['body'] . '"');
    }
  }
  
  /**
   * Object Mapper to convert the XML to array of useful PHP objects
   *
   * @param String $xml_string
   * @return Array of gapiReportResult objects
   */
  protected function objectMapper($xml_string)
  {
    $xml = simplexml_load_string($xml_string);
    
    $results = array();
    
    foreach($xml->entry as $entry)
    {
      $metrics = array();
      foreach($entry->children('http://schemas.google.com/analytics/2009')->metric as $metric)
      {
        $metrics[str_replace('ga:','',$metric->attributes()->name)] = strval($metric->attributes()->value);
      }
      
      $dimensions = array();
      foreach($entry->children('http://schemas.google.com/analytics/2009')->dimension as $dimension)
      {
        $dimensions[str_replace('ga:','',$dimension->attributes()->name)] = strval($dimension->attributes()->value);
      }
      
      $gapi_result = new gapiReportResult($metrics,$dimensions);
      $results[] = $gapi_result;
    }
    
    return $results;
  }
  
  /**
   * Authenticate Google Account with Google
   *
   * @param String $email
   * @param String $password
   */
  protected function authenticateUser($email, $password)
  {
    $post_variables = array(
      'accountType' => 'GOOGLE',
      'Email' => $email,
      'Passwd' => $password,
      'source' => gapi::interface_name,
      'service' => 'analytics'
    );
    
    $response = $this->httpRequest(gapi::client_login_url,null,$post_variables);
    
    //Convert newline delimited variables into url format then import to array
    parse_str(str_replace(array("\n","\r\n"),'&',$response['body']),$auth_token);
    
    if(substr($response['code'],0,1) != '2' || !is_array($auth_token) || empty($auth_token['Auth']))
    {
      throw new Exception('GAPI: Failed to authenticate user. Error: "' . $response['body'] . '"');
    }
    
    $this->auth_token = $auth_token['Auth'];
  }
  
  /**
   * Generate authentication token header for all requests
   *
   * @return Array
   */
  protected function generateAuthHeader()
  {
    return array('Authorization: GoogleLogin auth=' . $this->auth_token);
  }
  
  /**
   * Perform http request
   * 
   * Currently using CURL, but should be upgraded to support
   * other methods, like fopen with stream_context_create
   *
   * @todo Upgrade to support other request methods
   * @param Array $get_variables
   * @param Array $post_variables
   * @param Array $headers
   */
  protected function httpRequest($url, $get_variables=null, $post_variables=null, $headers=null)
  {
    $ch = curl_init();
    
    if(is_array($get_variables))
    {
      $get_variables = '?' . str_replace('&amp;','&',urldecode(http_build_query($get_variables)));
    }
    else 
    {
      $get_variables = null;
    }
    
    curl_setopt($ch, CURLOPT_URL, $url . $get_variables);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); //CURL doesn't like google's cert
    
    if(is_array($post_variables))
    {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $post_variables);
    }
    
    if(is_array($headers))
    {
      curl_setopt($ch, CURLOPT_HTTPHEADER,$headers);
    }
    
    $response = curl_exec($ch);
    $code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    return array('body'=>$response,'code'=>$code);
  }
}

/**
 * Class gapiReportResult
 * 
 * Storage for individual gapi report results
 *
 */
class gapiReportResult
{
  private $metrics = array();
  private $dimensions = array();
  
  public function __construct($metrics,$dimesions)
  {
    $this->metrics = $metrics;
    $this->dimensions = $dimesions;
  }
  
  /**
   * toString function to return the name of the result
   * this is a concatented string of the dimesions chosen
   * 
   * For example:
   * 'Firefox 3.0.10' from browser and browserVersion
   *
   * @return String
   */
  public function __toString()
  {
    if(is_array($this->dimensions))
    {
      return implode(' ',$this->dimensions);
    }
    else 
    {
      return '';
    }
  }
  
  /**
   * Get an associative array of the dimesions
   * and the matching values for the current result
   *
   * @return Array
   */
  public function getDimesions()
  {
    return $this->dimensions;
  }
  
  /**
   * Get an array of the metrics and the matchning
   * values for the current result
   *
   * @return Array
   */
  public function getMetrics()
  {
    return $this->metrics;
  }
  
  /**
   * Call method to find a matching metric or dimension to return
   *
   * @param $name String name of function called
   * @return String
   * @throws Exception if not a valid metric or dimensions, or not a 'get' function
   */
  public function __call($name,$parameters)
  {
    if(!preg_match('/^get/',$name))
    {
      throw new Exception('No such function "' . $name . '"');
    }
    
    $name = preg_replace('/^get/','',$name);
    
    $metric_key = $this->array_key_exists_nc($name,$this->metrics);
    
    if($metric_key)
    {
      return $this->metrics[$metric_key];
    }
    
    $dimension_key = $this->array_key_exists_nc($name,$this->dimensions);
    
    if($dimension_key)
    {
      return $this->dimensions[$dimension_key];
    }

    throw new Exception('No valid metric or dimesion called "' . $name . '"');
  }
  
  /**
   * Case insensitive array_key_exists function, also returns
   * matching key.
   *
   * @param String $key
   * @param Array $search
   * @return String Matching array key
   */
  private function array_key_exists_nc($key, $search)
  {
    if (array_key_exists($key, $search))
    {
      return $key;
    }
    if (!(is_string($key) && is_array($search)))
    {
      return false;
    }
    $key = strtolower($key);
    foreach ($search as $k => $v)
    {
      if (strtolower($k) == $key)
      {
        return $k;
      }
    }
    return false;
  } 
}