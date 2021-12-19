<?php
/**
 * Setting unlimited memorry, not advised in real life scenerio
 */
ini_set('memory_limit','-1');

class crawler
{
    protected $_url;
    protected $_depth;
    protected $_host;
    protected $_seen = array();
    protected $uniqueImages = array();
    protected $relativeUrl = array();
    protected $absoluteUrl = array();
    protected $wordCount = array();
    protected $titles = array();
    protected $stream = array();
    protected $pageTime;

    public function __construct($url, $depth = 5)
    {
        $this->_url = $url;
        $this->_depth = $depth;
        $parse = parse_url($url);
        $this->_host = $parse['host'];
    }

    protected function followLinks($content, $url, $depth)
    {
        $dom = new DOMDocument('1.0');
        @$dom->loadHTML($content);
        $anchors = $dom->getElementsByTagName('a');

        foreach ($anchors as $element) {
            $href = $element->getAttribute('href');
            if (0 !== strpos($href, 'http')) {
                $path = '/' . ltrim($href, '/');
                if (extension_loaded('http')) {
                    $href = http_build_url($url, array('path' => $path));
                } else {
                    $parts = parse_url($url);
                    $href = $parts['scheme'] . '://';
                    if (isset($parts['user']) && isset($parts['pass'])) {
                        $href .= $parts['user'] . ':' . $parts['pass'] . '@';
                    }
                    $href .= $parts['host'];
                    if (isset($parts['port'])) {
                        $href .= ':' . $parts['port'];
                    }
                    $href .= $path;
                }
                $this->uniqueURL($href, true);
            } else {
                // will only log external pages but will not crawl them
                $this->uniqueURL($href, false);
            }
            // Crawl only link that belongs to the start domain
            $this->crawl_page($href, $depth - 1);
        }
    }

    protected function processImages($content)
    {
        $dom = new DOMDocument('1.0');
        @$dom->loadHTML($content);
        $anchors = $dom->getElementsByTagName('img');

        foreach ($anchors as $element) {
            $href = $element->getAttribute('src');
            if (!in_array( $href, $this->uniqueImages)) {
                $this->uniqueImages[] = $href;
            }
        }
    }

    protected function getContent($url)
    {
        $handle = curl_init($url);
       curl_setopt($handle, CURLOPT_FOLLOWLOCATION, TRUE);
        // return the content
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);

        /* Get the HTML or whatever is linked in $url. */
        $response = curl_exec($handle);
        // response total time
        $time = curl_getinfo($handle, CURLINFO_TOTAL_TIME);
        /* Check for 404 (file not found). */
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);

        curl_close($handle);
        return array($response, $httpCode, $time);
    }

    protected function prepareStream($url, $depth, $httpcode, $time)
    {
        $currentDepth = $this->_depth - $depth;
        $count = count($this->_seen);
        $this->stream[] = array(
            "line" => $count,
            "httpcode" => $httpcode,
            "time" => $time,
            "currentDepth" => $currentDepth,
            "url" => $url
        );
    }

    protected function isValid($url, $depth)
    {
        if (strpos($url, $this->_host) === false
            || $depth === 0
            || isset($this->_seen[$url])
        ) {
            return false;
        }
        foreach ($this->_filter as $excludePath) {
            if (strpos($url, $excludePath) !== false) {
                return false;
            }
        }
        return true;
    }

    public function crawl_page($url, $depth)
    {
        if (!$this->isValid($url, $depth)) {
            return;
        }
        // add to the seen URL
        $this->_seen[$url] = true;
        // get Content and Return Code
        list($content, $httpcode, $time) = $this->getContent($url);
        // get all the images in a page
        $this->processImages($content);
        // get all the title
        $this->getTitle($content);
        // get all the content in the body section
        $this->getBody($content);
        // add all the time to a time array
        $this->pageTime[] = (float) $time;
        // print Result for current Page
        $this->prepareStream($url, $depth, $httpcode, $time);
        // process subPages
        $this->followLinks($content, $url, $depth);
    }

    public function run()
    {

        $result = "Crawling Started" . "<br>";
        $result .= "Start time: " . date('l jS \of F Y h:i:s A') . "<br>";
        $this->crawl_page($this->_url, $this->_depth);
        $result .= "End time: " . date('l jS \of F Y h:i:s A') . "<br><br>";
        $result .= "Number of pages crawled: " . count($this->_seen) . "<br>";
        $result .= "Number of a unique images: " . count($this->uniqueImages) . "<br>";
        $result .= "Number of unique internal links: " . count($this->relativeUrl) . "<br>";
        $result .= "Number of unique external links: " . count($this->absoluteUrl) . "<br>";
        $result .= "Average page load in seconds: " . $this->calculateAverage() . "<br>";
        $result .= "Average word count: " . $this->calculateAverageWordCount() . "<br>";
        $result .= "Average title length: " . $this->calculateAverageTitle() . "<br>";

        echo $result;

        $this->showStream();
    }

    private function getTitle($content) {
        $title = preg_match('/<title[^>]*>(.*?)<\/title>/ims', $content, $match) ? $match[1] : null;
        $this->titles[] = strlen($title);
    }

    private function getBody($content) {
        /**
         * get all the content in the body
         */
        $bodyContent = preg_match('/<body[^>]*>(.*?)<\/body>/ims', $content, $match) ? $match[1] : null;
        
        /**
         * remove all the data in the script tag to remove only text
         */
        $bodyContent = preg_replace("/<script[^>]*>([\s\S]*?)<\/script[^>]*>/",'',$bodyContent);


        $this->wordCount[] = strlen( strip_tags($bodyContent) );
    }

    private function uniqueURL($url, $relative = true) {
        if ($relative === true ) {
            if (!in_array( $url, $this->relativeUrl)) {
                $this->relativeUrl[] = $url;
            }
        } else {
            if (!in_array( $url, $this->absoluteUrl)) {
                $this->absoluteUrl[] = $url;
            }
        }
    }

    private function calculateAverage() {
        return number_format((array_sum( $this->pageTime )/count( $this->pageTime ) ), 4);
    }

    private function calculateAverageTitle() {
        return number_format((array_sum( $this->titles )/count( $this->titles ) ));
    }

    private function calculateAverageWordCount() {
        return number_format((array_sum( $this->wordCount )/count( $this->wordCount ) ));
    }

    private function showStream() {
        echo "<br>Crawled Pages<br>";
        echo "<table>";
        echo "<tr>";
        echo "  <td>Line</td>";
        echo "  <td>HTTP Code</td>";
        echo "  <td>Time</td>";
        echo "  <td>URL</td>";
        echo "</tr>";
        foreach($this->stream as $stream) {
            echo "<tr>";
            echo "  <td>" . $stream['line']. "</td>";
            echo "  <td>" . $stream['httpcode']. "</td>";
            echo "  <td>" . $stream['time']. "</td>";
            echo "  <td>" . $stream['url'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}

$startURL = 'https://agencyanalytics.com/';
$depth = 6;
$crawler = new crawler($startURL, $depth);
$crawler->run();
?>