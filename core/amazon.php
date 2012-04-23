<?php

namespace li3_amazon\core;

use SoapClient;
use SoapHeader;

use Exception;
use InvalidArgumentException;

/*

	Requiere : SOAP + OPEN SSL

	sudo port install php5-soap
	sudo port install php5-openssl
*/

class Amazon
{
	const CONNECTION_DEFAULT_NAME = "amazon";

	const RETURN_TYPE_ARRAY	= 1;
	const RETURN_TYPE_OBJECT = 2;

	/**
	 * Is the configuration loaded
	 *
	 * @var bool
	 */
	private static $_configLoaded = false;

	/**
	 * Baseconfigurationstorage
	 *
	 * @var array
	 */
	private static $requestConfig = array();

	/**
	 * Responseconfigurationstorage
	 *
	 * @var array
	 */
	private static $responseConfig = array(
		'returnType'					=> self::RETURN_TYPE_OBJECT,
		'responseGroup'			 => 'Small',
		'optionalParameters'	=> array()
	);

	/**
	 * All possible locations
	 *
	 * @var array
	 */
	private static $possibleLocations = array('de', 'com', 'co.uk', 'ca', 'fr', 'co.jp', 'it', 'cn', 'es');

	/**
	 * The WSDL File
	 *
	 * @var string
	 */
	//protected static $webserviceWsdl = 'http://webservices.amazon.com/AWSECommerceService/AWSECommerceService.wsdl';
	protected static $webserviceWsdl = 'http://ecs.amazonaws.com/AWSECommerceService/2009-10-01/US/AWSECommerceService.wsdl';
	/**
	 * The SOAP Endpoint
	 *
	 * @var string
	 */
	protected static $webserviceEndpoint = 'https://webservices.amazon.%%COUNTRY%%/onca/soap?Service=AWSECommerceService';

	/**
	 * Initialize connection to amazon
	 */
	public static function connect($data)
	{
		if(self::$_configLoaded) {
			throw new Exception('Connection already make');
		}

		$requiredParameters = array('key', 'secret');
		foreach($requiredParameters as $v) {
			if(!isset($data[$v]) || empty($data[$v])) {
				throw new Exception('Cannot load config required parameter '.$v.' is missing in Connection config');
			}
		}

		$defaults = array(
			'country' => 'fr',
			'returnType' => self::RETURN_TYPE_ARRAY,
			'associateTag' => 'xx',
			'responseGroup' => 'Large'
		);

		$data+= $defaults;

		// Configure request
		self::$requestConfig['accessKey'] = $data['key'];
		self::$requestConfig['secretKey'] = $data['secret'];
		self::country($data['country']);
		if(isset($data['MerchantId'])) {
			self::$requestConfig['MerchantId'] = $data['MerchantId'];
		}
		if(isset($data['associateTag'])) {
			self::$requestConfig['associateTag'] = $data['associateTag'];
		}

		// Configure response
		if(isset($data['returnType'])) {
			self::$responseConfig['returnType'] = $data['returnType'];
		}

		if(isset($data['responseGroup'])) {
			self::$responseConfig['responseGroup'] = $data['responseGroup'];
		}

		self::$_configLoaded = true;
	}

	protected static function _checkConfig() {
		if(!self::$_configLoaded) {
			throw new Exception('Connnection is not set, please call Amazon::connect before making a request');
		}
	}

	/**
	 * execute search
	 *
	 * @param string $pattern
	 *
	 * @return array|object return type depends on setting
	 *
	 * @see returnType()
	 */
	public static function search($pattern, $nodeId = null)
	{
		if (false === isset(self::$requestConfig['category']))
		{
			throw new Exception('No Category given: Please set it up before');
		}

		$browseNode = array();
		if (null !== $nodeId && true === self::validateNodeId($nodeId))
		{
			$browseNode = array('BrowseNode' => $nodeId);
		}

		$params = self::buildRequestParams('ItemSearch', array_merge(
			array(
				'Keywords' => $pattern,
				'SearchIndex' => self::$requestConfig['category']
			),
			$browseNode
		));

		return self::returnData(
			self::performSoapRequest("ItemSearch", $params)
		);
	}

	public static function lookup($asin, $country = null)
	{
		if($country) {
			self::country($country);
		}
		
		$params = self::buildRequestParams('ItemLookup', array(
			'ItemId' => $asin,
		));

		return self::returnData(
			self::returnItems(self::performSoapRequest("ItemLookup", $params))
		);
	}

	/**
	 * Implementation of BrowseNodeLookup
	 * This allows to fetch information about nodes (children anchestors, etc.)
	 *
	 * @param integer $nodeId
	 */
	public static function browseNodeLookup($nodeId)
	{
		self::validateNodeId($nodeId);

		$params = self::buildRequestParams('BrowseNodeLookup', array(
			'BrowseNodeId' => $nodeId
		));

		return self::returnData(
			self::performSoapRequest("BrowseNodeLookup", $params)
		);
	}

	/**
	 * Implementation of SimilarityLookup
	 * This allows to fetch information about product related to the parameter product
	 *
	 * @param string $asin
	 */
	public static function similarityLookup($asin)
	{
		$params = self::buildRequestParams('SimilarityLookup', array(
			'ItemId' => $asin
		));

		return self::returnData(
			self::performSoapRequest("SimilarityLookup", $params)
		);
	}

	/**
	 * Builds the request parameters
	 *
	 * @param string $function
	 * @param array	$params
	 *
	 * @return array
	 */
	protected static function buildRequestParams($function, array $params)
	{
		self::_checkConfig();
		$associateTag = array();

		if(false === empty(self::$requestConfig['associateTag']))
		{
			$associateTag = array('AssociateTag' => self::$requestConfig['associateTag']);
		}

		return array_merge(
			$associateTag,
			array(
				'AWSAccessKeyId' => self::$requestConfig['accessKey'],
				'Request' => array_merge(
					array('Operation' => $function),
					$params,
					self::$responseConfig['optionalParameters'],
					array('ResponseGroup' => self::prepareResponseGroup())
		)));
	}

	/**
	 * Prepares the responsegroups and returns them as array
	 *
	 * @return array|prepared responsegroups
	 */
	protected static function prepareResponseGroup()
	{
		if (false === strstr(self::$responseConfig['responseGroup'], ','))
			return self::$responseConfig['responseGroup'];

		return explode(',', self::$responseConfig['responseGroup']);
	}

	/**
	 * @param string $function Name of the function which should be called
	 * @param array $params Requestparameters 'ParameterName' => 'ParameterValue'
	 *
	 * @return array The response as an array with stdClass objects
	 */
	protected static function performSoapRequest($function, $params)
	{
		$soapClient = new SoapClient(
			self::$webserviceWsdl,
			array('exceptions' => 1)
		);

		$soapClient->__setLocation(str_replace(
			'%%COUNTRY%%',
			self::$responseConfig['country'],
			self::$webserviceEndpoint
		));

		$soapClient->__setSoapHeaders(self::buildSoapHeader($function));

		return $soapClient->__soapCall($function, array($params));
	}

	/**
	 * Provides some necessary soap headers
	 *
	 * @param string $function
	 *
	 * @return array Each element is a concrete SoapHeader object
	 */
	protected static function buildSoapHeader($function)
	{
		$timeStamp = self::getTimestamp();
		$signature = self::buildSignature($function . $timeStamp);

		return array(
			new SoapHeader(
				'http://security.amazonaws.com/doc/2007-01-01/',
				'AWSAccessKeyId',
				self::$requestConfig['accessKey']
			),
			new SoapHeader(
				'http://security.amazonaws.com/doc/2007-01-01/',
				'Timestamp',
				$timeStamp
			),
			new SoapHeader(
				'http://security.amazonaws.com/doc/2007-01-01/',
				'Signature',
				$signature
			)
		);
	}

	/**
	 * provides current gm date
	 *
	 * primary needed for the signature
	 *
	 * @return string
	 */
	protected static function getTimestamp()
	{
		return gmdate("Y-m-d\TH:i:s\Z");
	}

	/**
	 * provides the signature
	 *
	 * @return string
	 */
	protected static function buildSignature($request)
	{
		return base64_encode(hash_hmac("sha256", $request, self::$requestConfig['secretKey'], true));
	}

	/**
	 * Basic validation of the nodeId
	 *
	 * @param integer $nodeId
	 *
	 * @return boolean
	 */
	protected static function validateNodeId($nodeId)
	{
		if (false === is_numeric($nodeId) && $nodeId <= 0)
		{
			throw new InvalidArgumentException(sprintf('Node has to be a positive Integer.'));
		}

		return true;
	}

	/**
	 * Returns the response either as Array or Array/Object
	 *
	 * @param object $object
	 *
	 * @return mixed
	 */
	protected static function returnData($object)
	{
		switch (self::$responseConfig['returnType'])
		{
			case self::RETURN_TYPE_OBJECT:
				return $object;
			break;

			case self::RETURN_TYPE_ARRAY:
				return self::objectToArray($object);
			break;

			default:
				throw new InvalidArgumentException(sprintf(
					"Unknwon return type %s", self::$responseConfig['returnType']
				));
			break;
		}
	}

	protected static function returnItems($data) {
		if(isset($data->Items) && isset($data->Items->Item) && count($data->Items->Item) > 0) {
			return $data->Items->Item;
		} else {
			return array();
		}
	}

	/**
	 * Transforms the responseobject to an array
	 *
	 * @param object $object
	 *
	 * @return array An arrayrepresentation of the given object
	 */
	protected static function objectToArray($object)
	{
		$out = array();
		foreach ($object as $key => $value)
		{
			switch (true)
			{
				case is_object($value):
					$out[$key] = self::objectToArray($value);
				break;

				case is_array($value):
					$out[$key] = self::objectToArray($value);
				break;

				default:
					$out[$key] = $value;
				break;
			}
		}

		return $out;
	}

	/**
	 * set or get optional parameters
	 *
	 * if the argument params is null it will reutrn the current parameters,
	 * otherwise it will set the params and return itself.
	 *
	 * @param array $params the optional parameters
	 *
	 * @return array|AmazonECS depends on params argument
	 */
	public static function optionalParameters($params = null)
	{
		if (null === $params)
		{
			return self::$responseConfig['optionalParameters'];
		}

		if (false === is_array($params))
		{
			throw new InvalidArgumentException(sprintf(
				"%s is no valid parameter: Use an array with Key => Value Pairs", $params
			));
		}

		self::$responseConfig['optionalParameters'] = $params;
	}

	/**
	 * Set or get the country
	 *
	 * if the country argument is null it will return the current
	 * country, otherwise it will set the country and return itself.
	 *
	 * @param string|null $country
	 *
	 * @return string|AmazonECS depends on country argument
	 */
	public static function country($country = null)
	{
		if (null === $country)
		{
			return self::$responseConfig['country'];
		}

		if (false === in_array(strtolower($country), self::$possibleLocations))
		{
			throw new InvalidArgumentException(sprintf(
				"Invalid Country-Code: %s! Possible Country-Codes: %s",
				$country,
				implode(', ', self::$possibleLocations)
			));
		}

		self::$responseConfig['country'] = strtolower($country);
	}

	public static function category($category = null)
	{
		if (null === $category)
		{
			return isset(self::$requestConfig['category']) ? self::$requestConfig['category'] : null;
		}

		self::$requestConfig['category'] = $category;
	}

	public static function merchant($mid = null)
	{
		if (null === $mid)
		{
			return isset(self::$requestConfig['MerchantId']) ? self::$requestConfig['MerchantId'] : null;
		}

		self::$requestConfig['MerchantId'] = $mid;
	}


	public static function responseGroup($responseGroup = null)
	{
		if (null === $responseGroup)
		{
			return self::$responseConfig['responseGroup'];
		}

		self::$responseConfig['responseGroup'] = $responseGroup;
	}

	public static function returnType($type = null)
	{
		if (null === $type)
		{
			return self::$responseConfig['returnType'];
		}

		self::$responseConfig['returnType'] = $type;
	}

	/**
	 * Setter/Getter of the AssociateTag.
	 * This could be used for late bindings of this attribute
	 *
	 * @param string $associateTag
	 *
	 * @return string|AmazonECS depends on associateTag argument
	 */
	public static function associateTag($associateTag = null)
	{
		if (null === $associateTag)
		{
			return self::$requestConfig['associateTag'];
		}

		self::$requestConfig['associateTag'] = $associateTag;
	}

	/**
	 * @deprecated use returnType() instead
	 */
	public static function setReturnType($type)
	{
		return self::returnType($type);
	}

	/**
	 * Setting the resultpage to a specified value.
	 * Allows to browse resultsets which have more than one page.
	 *
	 * @param integer $page
	 *
	 * @return AmazonECS
	 */
	public static function page($page)
	{
		if (false === is_numeric($page) || $page <= 0)
		{
			throw new InvalidArgumentException(sprintf(
				'%s is an invalid page value. It has to be numeric and positive',
				$page
			));
		}

		self::$responseConfig['optionalParameters'] = array_merge(
			self::$responseConfig['optionalParameters'],
			array("ItemPage" => $page)
		);
	}
}