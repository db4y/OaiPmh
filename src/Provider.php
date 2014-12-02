<?php
/**
 * Created by PhpStorm.
 * User: jsmit
 * Date: 28-11-14
 * Time: 15:42
 */

namespace Picturae\OAI;


use Picturae\OAI\Exception\BadArgumentException;
use Picturae\OAI\Exception\BadVerbException;
use Picturae\OAI\Exception\MultipleExceptions;
use Picturae\OAI\Exception\NoMetadataFormatsException;
use Picturae\OAI\Exception\NoRecordsMatchException;
use Picturae\OAI\Exception\NoSetHierarchyException;
use Picturae\OAI\Interfaces\RecordList as RecordListInterface;
use Picturae\OAI\Interfaces\Repository;

/**
 * Class Provider
 *
 * @example
 * <code>
 *
 * //create provider object
 * $provider = new Picturae\OAI\Provider($someRepository);
 * //where some $someRepository is an implementation of \Picturae\OAI\Interfaces\Repository
 *
 * // add request variables, this could be just $_GET or $_POST in case of a post but can also come from a different
 * // source
 * $provider->setRequest($get);
 *
 * //run the oai provider this will return a object containing all headers and output
 * $response = $provider->execute();
 *
 * //output headers, body and then exit (it is possible to do manipulations before outputting but this is not advised.
 * $response->outputAndExit();
 * </code>
 * @package Picturae\OAI
 */
class Provider
{

    /**
     * @var array containing all verbs and the arguments they except
     */
    private static $verbs = [
        "Identify" => array(),
        "ListMetadataFormats" => array('identifier'),
        "ListSets" => array('resumptionToken'),
        "GetRecord" => array('identifier', 'metadataPrefix'),
        "ListIdentifiers" => array('from', 'until', 'metadataPrefix', 'set', 'resumptionToken'),
        "ListRecords" => array('from', 'until', 'metadataPrefix', 'set', 'resumptionToken')
    ];

    /**
     * @var string the verb of the current request
     */
    private $verb;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var Repository
     */
    private $repository;

    /**
     * @var array
     */
    private $request = [];

    /**
     * @param Repository $repository
     */
    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @return array
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @param array $request
     */
    public function setRequest(array $request)
    {
        $this->request = $request;
    }

    /**
     * @param \DateTime $time
     * @return string
     */
    private function toUtcDateTime(\DateTime $time)
    {
        $UTC = new \DateTimeZone("UTC");
        $time->setTimezone($UTC);
        return $time->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * handles the current request
     * @return Response
     */
    public function execute()
    {
        $this->response = new Response();
        $this->response->addElement("responseDate", $this->toUtcDateTime(new \DateTime()));
        $requestNode = $this->response->createElement("request", $this->repository->identify()->getBaseUrl());
        $this->response->getDocument()->documentElement->appendChild($requestNode);

        try {
            $this->checkVerb();
            $verbOutput = $this->doVerb();

            // we are sure now that all request variables are correct otherwise an error would have been thrown
            foreach ($this->request as $k=>$v) {
                $requestNode->setAttribute($k, $v);
            }

            // the element is only added when everything went fine, otherwise we would add error node(s) in the catch
            // block below
            $this->response->getDocument()->documentElement->appendChild($verbOutput);
        } catch (MultipleExceptions $errors) {
            //multiple errors happened add all of the to the response
            foreach ($errors as $error) {
                $this->response->addError($error);
            }
        } catch (Exception $error) {
            //add this error to the response
            $this->response->addError($error);
        }

        return $this->response;
    }

    /**
     * executes the right function for the current verb
     * @return \DOMElement
     */
    private function doVerb()
    {
        switch ($this->verb) {
            case "Identify":
                return $this->identify();
                break;
            case "ListMetadataFormats":
                return $this->listMetadataFormats();
                break;
            case "ListSets":
                return $this->listSets();
                break;
            case "ListRecords":
                return $this->listRecords();
                break;
            case "ListIdentifiers":
                return $this->listIdentifiers();
                break;
            default:
                //shouldn't be possible to come here because verb was already checked, but just in case
                throw new BadVerbException("$this->verb is not a valid verb");
        }
    }

    /**
     * handles Identify requests
     * @return \DOMElement
     */
    private function identify()
    {
        $identity = $this->repository->identify();
        $identityNode = $this->response->createElement('Identify');

        // create a node for each property of identify
        $identityNode->appendChild($this->response->createElement('repositoryName', $identity->getRepositoryName()));
        $identityNode->appendChild($this->response->createElement('baseURL', $identity->getBaseUrl()));
        $identityNode->appendChild($this->response->createElement('protocolVersion', '2.0'));
        foreach ($identity->getAdminEmails() as $email) {
            $identityNode->appendChild($this->response->createElement('adminEmail', $email));
        }
        $identityNode->appendChild(
            $this->response->createElement('earliestDatestamp', $this->toUtcDateTime($identity->getEarliestDatestamp()))
        );
        $identityNode->appendChild($this->response->createElement('deletedRecord', $identity->getDeletedRecord()));
        $identityNode->appendChild($this->response->createElement('granularity', $identity->getGranularity()));
        if ($identity->getCompression()) {
            $identityNode->appendChild($this->response->createElement('compression', $identity->getCompression()));
        }
        if ($identity->getDescription()) {
            $identityNode->appendChild($this->response->createElement('description', $identity->getDescription()));
        }

        return $identityNode;
    }

    /**
     * handles ListMetadataFormats requests
     * @return \DOMElement
     */
    private function listMetadataFormats()
    {
        $listNode = $this->response->createElement('ListMetadataFormats');

        $identifier = isset($this->request['identifier']) ? $this->request['identifier'] : null;
        $formats = $this->repository->listMetadataFormats($identifier);

        if (!count($formats)) {
            throw new NoMetadataFormatsException();
        }

        //create a node for each metadataFormat
        foreach ($formats as $format) {
            $formatNode = $this->response->createElement('metadataFormat');
            $formatNode->appendChild($this->response->createElement("metadataPrefix", $format->getPrefix()));
            $formatNode->appendChild($this->response->createElement("schema", $format->getSchema()));
            $formatNode->appendChild($this->response->createElement("metadataNamespace", $format->getNamespace()));
            $listNode->appendChild($formatNode);
        }
        return $listNode;
    }

    /**
     * checks if the provided verb is correct and if the arguments supplied are allowed for this verb
     */
    private function checkVerb()
    {
        if (!isset($this->request['verb'])) {
            throw new BadVerbException("Verb is missing");
        }

        $this->verb = $this->request['verb'];
        if (is_array($this->verb)) {
            throw new BadVerbException("Only 1 verb allowed, multiple given");
        }
        if (!array_key_exists($this->verb, self::$verbs)) {
            throw new BadVerbException("$this->verb is not a valid verb");
        }

        $requestParams = $this->request;
        unset($requestParams['verb']);

        $errors = [];
        foreach (array_diff(array_keys($requestParams), self::$verbs[$this->verb]) as $key => $value) {
            $errors[] = new BadArgumentException(
                "Argument {$key} is not allowed for verb $this->verb. " .
                "Allowed arguments are: " . implode(", ", self::$verbs[$this->verb])
            );
        }
        if (count($errors)) {
            throw (new MultipleExceptions())->setExceptions($errors);
        }

        //if the resumption token is set it should be the only argument
        if (isset($requestParams['resumptionToken']) && count($requestParams) > 1) {
            throw new BadArgumentException("resumptionToken can not be used together with other arguments");
        }
    }

    /**
     * handles ListSets requests
     * @return \DOMElement
     */
    private function listSets()
    {
        $listNode = $this->response->createElement('ListSets');

        // fetch the sets either by resumption token or without
        if (isset($this->request['resumptionToken'])) {
            $sets = $this->repository->listSetsByToken($this->request['resumptionToken']);
        } else {
            $sets = $this->repository->listSets();
            if (!count($sets->getItems())) {
                throw new NoSetHierarchyException();
            }
        }

        //create node for all sets
        foreach($sets->getItems() as $set) {
            $setNode = $this->response->createElement('set');
            $setNode->appendChild($this->response->createElement('setSpec', $set->getSpec()));
            $setNode->appendChild($this->response->createElement('setName', $set->getName()));
            if ($set->getDescription()) {
                $setNode->appendChild($this->response->createElement('setDescription', $set->getDescription()));
            }
            $listNode->appendChild($setNode);
        }

        $this->addResumptionToken($sets, $listNode);

        return $listNode;
    }

    /**
     * handles ListSets Records
     * @return \DOMElement
     */
    private function listRecords()
    {
        $listNode = $this->response->createElement('ListRecords');
        if (isset($this->request['resumptionToken'])) {
            $records = $this->repository->listRecordsByToken($this->request['resumptionToken']);
        } else {
            list($from, $until, $set) = $this->getRecordListParams();

            $metadataFormat = $this->request['metadataPrefix'];
            $records = $this->repository->listRecords($metadataFormat, $from, $until, $set);

            if (!count($records->getItems())) {
                //maybe this is because someone tries to fetch from a set and we don't support that
                if ($set && !count($this->repository->listSets()->getItems())) {
                    throw new NoSetHierarchyException();
                }
                throw new NoRecordsMatchException();
            }
        }

        //create 'record' node for each record with a 'header', 'metadata' and possibly 'about' node
        foreach ($records->getItems() as $record) {
            $recordNode = $this->response->createElement('record');
            $recordNode->appendChild($this->getRecordHeaderNode($record));
            $recordNode->appendChild($this->response->createElement('metadata', $record->getMetadata()));

            //only add an 'about' node if it's not null
            $about = $record->getAbout();
            if ($about !== null) {
                $recordNode->appendChild($this->response->createElement('about', $about));
            }

            $listNode->appendChild($recordNode);
        }

        $this->addResumptionToken($records, $listNode);

        return $listNode;
    }

    /**
     * handles ListIdentifiers requests
     * @return \DOMElement
     */
    private function listIdentifiers()
    {
        $listNode = $this->response->createElement('ListRecords');
        if (isset($this->request['resumptionToken'])) {
            $records = $this->repository->listRecordsByToken($this->request['resumptionToken']);
        } else {
            list($from, $until, $set) = $this->getRecordListParams(false);
            $records = $this->repository->listRecords(null, $from, $until, $set);

            if (!count($records->getItems())) {
                //maybe this is because someone tries to fetch from a set and we don't support that
                if ($set && !count($this->repository->listSets()->getItems())) {
                    throw new NoSetHierarchyException();
                }
                throw new NoRecordsMatchException();
            }
        }

        // create 'record' with only headers
        foreach ($records->getItems() as $record) {
            $recordNode = $this->response->createElement('record');
            $recordNode->appendChild($this->getRecordHeaderNode($record));
            $listNode->appendChild($recordNode);
        }

        $this->addResumptionToken($records, $listNode);

        return $listNode;
    }

    /**
     * Converts the header of a record to a header node, used for both ListRecords and ListIdentifiers
     * @param Record $record
     * @return \DOMElement
     */
    private function getRecordHeaderNode(Record $record){
        $headerNode = $this->response->createElement('header');
        $header = $record->getHeader();
        $headerNode->appendChild($this->response->createElement('identifier', $header->getIdentifier()));
        $headerNode->appendChild(
            $this->response->createElement('datestamp', $this->toUtcDateTime($header->getDatestamp()))
        );
        foreach ($header->getSetSpecs() as $setSpec) {
            $headerNode->appendChild($this->response->createElement('setSpec', $setSpec));
        }
        if ($header->isDeleted()) {
            $headerNode->setAttribute("status", "deleted");
        }
        return $headerNode;
    }

    /**
     * does all the checks in the closures and merge any exceptions into one big exception
     * @param \Closure[] $checks
     */
    private function doChecks($checks){
        $errors = [];
        foreach ($checks as $check) {
            try {
                $check();
            } catch (Exception $e) {
                $errors[] = $e;
            }
        }
        if (count($errors)) {
            throw (new MultipleExceptions)->setExceptions($errors);
        }
    }

    /**
     * Converts a date coming from a request param and converts it to a \DateTime
     * @param string $date
     * @return \DateTime
     * @throws BadArgumentException when the date is invalid or not supplied in the right format
     */
    private function parseRequestDate($date)
    {
        $timezone = new \DateTimeZone("UTC");
        $parsedDate = date_create_from_format('Y-m-d\TH:i:s\Z', $date, $timezone);
        if (!$parsedDate) {
            $parsedDate = date_create_from_format('Y-m-d', $date, $timezone);
        }

        $parseResult = date_get_last_errors();
        if (!$parsedDate || ($parseResult['error_count'] > 0) || ($parseResult['warning_count'] > 0)) {
            throw new BadArgumentException("$date is not a valid date");
        }

        return $parsedDate;
    }

    /**
     * Adds a resumptionToken to a a listNode if the is a resumption token otherwise it does nothing
     * @param RecordListInterface $recordList
     * @param \DomElement $listNode
     */
    private function addResumptionToken($recordList, $listNode)
    {
        if ($recordList->getResumptionToken()) {
            $resumptionTokenNode = $this->response->createElement('resumptionToken', $recordList->getResumptionToken());
            $listNode->appendChild($resumptionTokenNode);
        }
    }

    /**
     * parses request arguments used by both ListIdentifiers and ListRecrods
     * @param bool $checkMetadataPrefix
     * @return array
     */
    private function getRecordListParams($checkMetadataPrefix = true)
    {
        $from = null;
        $until = null;
        $set = isset($this->request['set']) ? $this->request['set'] : null;

        $checks =[

            function () use (&$from) {
                if (isset($this->request['from'])) {
                    $from = $this->parseRequestDate($this->request['from']);
                }
            },
            function () use (&$until) {
                if (isset($this->request['until'])) {
                    $until = $this->parseRequestDate($this->request['until']);
                }
            },
        ];
        if ($checkMetadataPrefix === true) {
            $checks[] = function () {
                if (!isset($this->request['metadataPrefix'])) {
                    throw new BadArgumentException("Missing required argument metadataPrefix");
                }
            };
        }

        $this->doChecks($checks);
        return array($from, $until, $set);
    }
}