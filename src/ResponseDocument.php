<?php
/**
 * Created by PhpStorm.
 * User: jsmit
 * Date: 28-11-14
 * Time: 15:42
 */

namespace Picturae\OaiPmh;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Class ResponseDocument
 * @internal
 */
class ResponseDocument
{

    /**
     * @var string
     */
    private $output;

    /**
     * @var string[]
     */
    private $headers = ['Content-Type: text/xml; charset=utf8'];

    /**
     * @var string
     */
    private $status = '200';

    /**
     * @var \DOMDocument
     */
    private $document;

    /**
     * @return \DOMDocument
     */
    public function getDocument()
    {
        return $this->document;
    }

    /**
     * @param \DOMDocument $document
     */
    public function setDocument($document)
    {
        $this->document = $document;
    }
    /**
     *
     */
    public function __construct()
    {
        $this->document = new \DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;
        $documentElement = $this->document->createElementNS('http://www.openarchives.org/OAI/2.0/', "OAI-PMH");
        $documentElement->setAttribute('xmlns', 'http://www.openarchives.org/OAI/2.0/');
        $documentElement->setAttributeNS(
            "http://www.w3.org/2001/XMLSchema-instance",
            'xsi:schemaLocation',
            'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd'
        );

        $this->document->appendChild($documentElement);
    }

    /**
     * @param $name
     * @param string $value
     * @return \DOMElement
     */
    public function addElement($name, $value = null)
    {
        $element = $this->createElement($name, $value);
        $this->document->documentElement->appendChild($element);
        return $element;
    }

    /**
     * adds an error node base on a Exception
     * @param Exception $error
     */
    public function addError(Exception $error)
    {
        $errorNode = $this->addElement("error", $error->getMessage());
        $this->status = '400';
        if ($error->getErrorName()) {
            $errorNode->setAttribute("code", $error->getErrorName());
        }
    }

    /**
     * @param \string[] $headers
     */
    public function setHeaders($headers)
    {
        $this->headers = $headers;
    }

    /**
     * @param $header string
     * @return $this
     */
    public function addHeader($header)
    {
        $this->headers [] = $header;
        return $this;
    }

    /**
     * @param string $name
     * @param \DOMDocument|string $value
     * @return \DOMElement
     */
    public function createElement($name, $value = null)
    {
        $nameSpace = 'http://www.openarchives.org/OAI/2.0/';

        if ($value instanceof \DOMDocument) {
            $element = $this->document->createElementNS($nameSpace, $name, null);
            $node = $this->document->importNode($value->documentElement, true);
            $element->appendChild($node);
        } else {
            $element = $this->document->createElementNS($nameSpace, $name, $value);
        }
        return $element;
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return new Response($this->status, $this->headers, $this->document->saveXML());
    }
}