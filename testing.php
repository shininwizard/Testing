<?php

require_once 'bootstrap.php';

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use PHPMailer\PHPMailer\PHPMailer;

class Parser {
    /**
     * @var GuzzleHttp\Client
     */
    private $client;

    /**
     * @var array
     */
    private $data = [];
    private $urls = [];

    public function __construct(Client $client) {
        $this->client = $client;
    }

    private function getNewIdentity($ip = '127.0.0.1', $port = '9051', $auth = '') {
        $fp = fsockopen($ip, $port, $errno, $errstr, 10);
        if (!$fp) {
            echo "ERROR: $errno : $errstr";
            return false;
        } else {
            fwrite($fp,"AUTHENTICATE \"".$auth."\"\n");
            $response = fread($fp, 512);
            fwrite($fp, "signal NEWNYM\n");
            $response = fread($fp, 512);
        }
        fclose($fp);
        sleep(5);
        return true;
    }

    public function getUrls($url, $pages = 1) {
        $pagination[] = $url;

        for ($i = 2; $i <= $pages; $i++) {
            $pagination[] = $url . "?page=" . (string) $i;
        }

        foreach ($pagination as $page) {
            sleep(2);
            $this->requestPage($page);
        }
    }

    private function requestPage($page) {
        $retry = false;
        $promise = $this->client->getAsync($page, ['proxy' => 'socks5://localhost:9050'])->then(
            function (\Psr\Http\Message\ResponseInterface $response) {
                $this->urls[] = $this->extractFromPage((string) $response->getBody());
            },
            function (\GuzzleHttp\Exception\RequestException $e) use (&$retry) {
                $this->getNewIdentity();
                $retry = true;
            });
        $promise->wait();
        if ($retry) { $this->requestPage($page); }
    }

    private function extractFromPage($page) {
        $crawler = new Crawler($page);

        $urls = $crawler->filter('.lst-info-left-part > a')->extract(['href']);
        foreach ($urls as &$url) { $url = "https://www.carid.com" . $url; }

        return $urls;
    }

    public function parse(array $urls = []) {
        foreach ($urls as $url) {
            sleep(2);
            $this->requestUrl($url);
        }
    }

    private function requestUrl($url) {
        $retry = false;
        $promise = $this->client->getAsync($url, ['proxy' => 'socks5://localhost:9050'])->then(
            function (\Psr\Http\Message\ResponseInterface $response) use ($url) {
                $this->data[] = $this->extractFromHtml((string) $response->getBody(), $url);
            },
            function (\GuzzleHttp\Exception\RequestException $e) use (&$retry) {
                $this->getNewIdentity();
                $retry = true;
            });
        $promise->wait();
        if ($retry) { $this->requestUrl($url); }
    }

    private function extractFromHtml($html, $url) {
        $crawler = new Crawler($html);

        $id = trim($crawler->filter('[itemprop="sku"]')->text());
        $title = trim($crawler->filter('h1')->text());
        $price = trim($crawler->filter('.js-product-price-hide')->text());
//        $images = $crawler->filter('.prod-gallery-thumbs > a')->extract(['href']);
//        foreach ($images as &$image) { $image = "https://www.carid.com" . $image; }
        $script = $crawler->filter('.wrap > script')->eq(1)->text();
		$media = $this->extractFromJson($script);
        $features = $crawler->filter('.js-spoiler-block li')->extract(['_text']);
        if (empty($features)) {
            $crawler->filter('.ov_hidden > p')->each(function (Crawler $crawler) {
                foreach ($crawler->children() as $node) { $node->parentNode->removeChild($node); }
            });
            $features = $crawler->filter('.ov_hidden > img')->extract(['alt']);
            foreach ($features as &$feature) {
                $feature .= ': ' . $crawler->filter('.ov_hidden > p')->text();
            }
        }
        try {
            $reviewCount = trim($crawler->filter('[itemprop="reviewCount"]')->text());
        } catch (Exception $e) {
            $reviewCount = '0';
        }
        $reviewDates = $crawler->filter('[itemprop="datePublished"]')->extract(['content']);
        if ((int) $reviewCount > 10) {
            $nextReviews = $this->requestNextReviews($id, $reviewCount, $url);
            foreach ($nextReviews as $nextReview) {
                $reviewDates[] = $nextReview;
            }
        }

        return[
            'id' => $id,
            'title' => $title,
            'price' => $price,
            'images' => $media['images'],
            'videos' => $media['videos'],
			'pdf' => $media['pdf'],
            'features' => $features,
            'reviewCount' => $reviewCount,
            'reviewDates' => $reviewDates
        ];
    }

    private function requestNextReviews($id, $reviewCount, $url) {
        $moreReviews = [];
        for ($i = 10; $i <= (int) $reviewCount; $i += 10) {
            $response = $this->client->request(
                'POST',
                'https://www.carid.com/submit_review.php', [
                    'headers' => [
                        ':authority' => 'www.carid.com',
                        ':method' => 'POST',
                        ':path' => '/submit_review.php',
                        ':scheme' => 'https',
                        'accept' => 'text/html, */*; q=0.01',
                        'accept-encoding' => 'gzip, deflate, br',
                        'accept-language' => 'en-US,en;q=0.9,jw;q=0.8,ru;q=0.7,tr;q=0.6',
                        'content-length' => 92,
                        'content-type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                        'cookie' => 'xid=f4277d4daea7b8063ddcc6c3735757e7; xidRes=f4277d4daea7b8063ddcc6c3735757e7; store_language=US; _ga=GA1.2.1121295384.1542654096; RefererCookie=https%3A%2F%2Fwww.carid.com%2Fsuspension-systems.html; tracker_device=ae314f43-a76e-4b1b-a992-6f62dbe7e849; _ceg.s=pjohr3; _ceg.u=pjohr3; _gid=GA1.2.1764827410.1544867203; uxid=12bb13Pe0d34360ffdR10b; uxid2=104hhfDe0d34367817Vc7; uxid3=1hl67lKe0d34a69a25La3; uxat=%13%18JNH%07%13%0D6%3C%0B%0F!%012%035%2F6F%07%12%13%0F%09%0E%1E%07%13%1F%1D%1A%17%08%1E%07%14%0F%1D%1A%17%08%1E%07%08%17%0F%09%0E%1E%07%16%16%0F%09%0E%1E%07%16%1F%0F%09%0E%1E%07%0F%08%07%0F%16%07%10%1F%0F%09%0E%1E%07%15%16%0F%09%0E%1E%07%0D%08%13%12%1F%1F%1E%15%07%0C%10%07%1F%1F%07%1F%10%07%0C%1F%07%15%0C%1D%1A%17%08%1E%07%1F%19%1D%1A%17%08%1E%07',
                        'origin' => 'https://www.carid.com',
                        'referer' => $url,
                        'user-agent' => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/71.0.3578.98 Safari/537.36',
                        'x-requested-with' => 'XMLHttpRequest'
                    ],
                    'form_params' => [
                        'offset' => $i,
                        'type' => 'new',
                        'id' => preg_replace('/[^0-9]/', '', $id),
                        'action' => 'getSuperProductReviews'
                    ]
                ]
            );
            $body = (string) $response->getBody();
            $crawler = new Crawler($body);
            foreach ($crawler->filter('.prod_rvw')->extract(['data-offset_date']) as $date) {
                $moreReviews[] = $date;
            }
        }

        return $moreReviews;
    }

	private function extractFromJson($script) {
		$matches = array();
		preg_match('/(?<=CRD\.gallery = )(.*)(?=;)/', $script, $matches);
		$jsonMedia = $matches[0];
		$jsonMedia = preg_replace('/(\'alt\'\: \'.+?\'\,)/', '', $jsonMedia);
		$jsonMedia = str_replace(array("'", ",]"), array('"', ']'), $jsonMedia);
		$media = json_decode($jsonMedia, true);
		if (json_last_error() === JSON_ERROR_NONE) {
			$images = "https://www.carid.com" . implode(', https://www.carid.com', array_column($media['images'], 'path'));
			$videos = !empty(array_column($media['videos'], 'path')) ?
                "https://www.carid.com" . implode(', https://www.carid.com', array_column($media['videos'], 'path')) : '';
			$pdfOnly = [];
			foreach (array_column($media['files'], 'path') as $file) {
				if (strpos($file, '.pdf') > 0) $pdfOnly[] = $file;
			}
			$pdf = !empty($pdfOnly) ? "https://www.carid.com" . implode(', https://www.carid.com', $pdfOnly) : '';
		} else {
			$images = 'Invalid json';
			$videos = 'Invalid json';
			$pdf = 'Invalid json';
		}
		
		return [
			'images' => $images,
			'videos' => $videos,
			'pdf' => $pdf
		];
	}
	
    public function getProductUrls() {
        return $this->urls;
    }

    public function getProductData() {
        return $this->data;
    }
}

function sendMail($ini) {
    $email = new PHPMailer();

    $email->setFrom('from@email.com', 'Your Name', 0);
    $email->setFrom($ini['mail_from'], $ini['mail_name'], 0);
    $email->Subject = 'CARiD suspension systems | ' . date('Y-m-d H:i:s');
    $email->Body = 'What up';
    $email->addAddress($ini['mail_dest']);
    if (file_exists('new_products.csv') && file_exists('disappeared_products.csv') && file_exists('recently_reviewed_products.csv')) {
        $email->addAttachment('new_products.csv');
        $email->addAttachment('disappeared_products.csv');
        $email->addAttachment('recently_reviewed_products.csv');
    } else {
        $email->addAttachment('products.csv');
    }
    $email->Host = $ini['smtp_host'];
    $email->SMTPSecure = 'ssl';
    $email->Port = 465;
    $email->isSMTP();
    $email->SMTPAuth = true;
    $email->Username = $ini['mail_user'];
    $email->Password = $ini['mail_pass'];
    if ($email->send()) { echo "ok\n"; } else { echo "not ok\n" . $email->ErrorInfo; };
}

$client = new Client();
$parser = new Parser($client);

echo "Getting product urls...";
$parser->getUrls("https://www.carid.com/suspension-systems.html", 5);
echo "done\n";

echo "Getting product data...";
foreach ($parser->getProductUrls() as $urls) {
    $parser->parse($urls);
}
echo "done\n";

echo "Writing to database...";
foreach ($parser->getProductData() as $item) {
	$product = $entityManager->getRepository('Product')->findOneBy(['sku' => $item['id']]);
    foreach ($item['reviewDates'] as &$date) {
        $date = date("m-d-Y", strtotime($date));
    }
    $reviews = implode(', ', $item['reviewDates']);
	if ($product === null) {
		$product = new Product();
		$product->setState('N');
	} else {
		if (in_array($product->getState(), ['N', 'A', 'R'], true) && $product->getReviews() !== $reviews) { $product->setState('R'); }
		if (in_array($product->getState(), ['N', 'A', 'R'], true) && $product->getReviews() === $reviews) { $product->setState('A'); }
		if (in_array($product->getState(), ['O', 'D'], true)) { $product->setState('N'); }
	}
	$product->setSku($item['id']);
	$product->setName($item['title']);
	$product->setPrice($item['price']);
	$product->setImages($item['images']);
	$product->setVideos($item['videos']);
	$product->setPdf($item['pdf']);
	$product->setFeatures(implode('[:os:]', $item['features']));
    $product->setReviews($reviews);
	$entityManager->persist($product);
}
$entityManager->flush();

$skuList = array_column($parser->getProductData(), 'id');
$query = $entityManager->createQuery("SELECT p FROM Product p WHERE p.sku NOT IN(:sku)");
$query->setParameter('sku', $skuList);
$deadProducts = $query->getResult();
foreach ($deadProducts as $product) {
	if (in_array($product->getState(), ['N', 'A', 'R'], true)) { 
		$product->setState('D');
	} else { 
		$product->setState('O');
	}
	$entityManager->persist($product);
}
$entityManager->flush();
echo "done\n";

echo "Writing files...";
$entityManager->getRepository('Product')->writeCsv();
echo "done\n";

echo "Sending mail...";
sendMail($ini);
