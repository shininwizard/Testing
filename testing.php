<?php

require 'vendor\autoload.php';

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use PHPMailer\PHPMailer\PHPMailer;

class Product {
    private $productId;
    private $productName;
    private $productPrice;
    private $productImages;
    private $productVideo;
    private $productPdf;
    private $productFeatures;
    private $productReviewCount;
    private $productReviewDates;

    public function setProductData(array $data = []) {
        $this->productId = $data['id'];
        $this->productName = $data['title'];
        $this->productPrice = $data['price'];
        $matches = array();
        preg_match('/(?<=CRD\.gallery = )(.*)(?=;)/', $data['media'], $matches);
        $jsonMedia = $matches[0];
        $jsonMedia = preg_replace('/(\'alt\'\: \'.+?\'\,)/', '', $jsonMedia);
        $jsonMedia = str_replace(array("'", ",]"), array('"', ']'), $jsonMedia);
        $media = json_decode($jsonMedia, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $this->productImages = "https://www.carid.com" . implode(', https://www.carid.com', array_column($media['images'], 'path'));
            !empty(array_column($media['videos'], 'path')) ?
                $this->productVideo = "https://www.carid.com" . implode(', https://www.carid.com', array_column($media['videos'], 'path')) : '';
            $pdfOnly = [];
            foreach (array_column($media['files'], 'path') as $file) {
                if (strpos($file, '.pdf') > 0) $pdfOnly[] = $file;
            }
            !empty($pdfOnly) ? $this->productPdf = "https://www.carid.com" . implode(', https://www.carid.com', $pdfOnly) : '';
        } else {
            $this->productImages = 'Invalid json';
        }
        $this->productFeatures = implode('[:os:]', $data['features']);
        foreach ($data['reviewDates'] as &$date) {
            $date = date("m-d-Y", strtotime($date));
        }
        $this->productReviewCount = $data['reviewCount'];
        $this->productReviewDates = implode(', ', $data['reviewDates']);
    }

    public function getProductData() {
        return [$this->productId,
                $this->productName,
                $this->productPrice,
                $this->productImages,
                $this->productVideo,
                $this->productPdf,
                $this->productFeatures,
                $this->productReviewDates];
    }
}

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

    public function getUrls($url, $pages = 1) {
        $pagination[] = $url;

        for ($i = 2; $i <= $pages; $i++) {
            $pagination[] = $url . "?page=" . (string) $i;
        }

        foreach ($pagination as $page) {
            $promise = $this->client->getAsync($page)->then(
                function (\Psr\Http\Message\ResponseInterface $response) {
                    $this->urls[] = $this->extractFromPage((string) $response->getBody());
                });
            $promise->wait();
        }
    }

    private function extractFromPage($page) {
        $crawler = new Crawler($page);

        $urls = $crawler->filter('.lst-info-left-part > a')->extract(['href']);
        foreach ($urls as &$url) { $url = "https://www.carid.com" . $url; }

        return $urls;
    }

    public function parse(array $urls = []) {
        foreach ($urls as $url) {
            $promise = $this->client->getAsync($url)->then(
                function (\Psr\Http\Message\ResponseInterface $response) use ($url) {
                    $this->data[] = $this->extractFromHtml((string) $response->getBody(), $url);
                });
            $promise->wait();
        }
    }

    private function extractFromHtml($html, $url) {
        $crawler = new Crawler($html);

        $id = trim($crawler->filter('[itemprop="sku"]')->text());
        $title = trim($crawler->filter('h1')->text());
        $price = trim($crawler->filter('.js-product-price-hide')->text());
        $images = $crawler->filter('.prod-gallery-thumbs > a')->extract(['href']);
        foreach ($images as &$image) { $image = "https://www.carid.com" . $image; }
        $media = $crawler->filter('.wrap > script')->eq(1)->text();
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
            'images' => $images,
            'media' => $media,
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

    public function getProductUrls() {
        return $this->urls;
    }

    public function getProductData() {
        return $this->data;
    }
}

function writeDB($ini, $productData) {
	$server = $ini['db_serv'];
	$user = $ini['db_user'];
	$pass = $ini['db_pass'];
	$db = $ini['db_name'];
	
	try {
		$dbh = new PDO("mysql:host=$server;dbname=$db", $user, $pass);
	} catch(PDOException $e) {
		$dbh = new PDO("mysql:host=$server", $user, $pass);
		$sql = "CREATE DATABASE $db";
		$dbh->exec($sql);
		$dbh = new PDO("mysql:host=$server;dbname=$db", $user, $pass);
		$sql = "CREATE TABLE Products (
				id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				sku VARCHAR(30) NOT NULL,
				name VARCHAR(250) NOT NULL,
				price VARCHAR(30) NOT NULL,
				images TEXT,
				videos TEXT,
				pdf TEXT,
				features TEXT,
				reviews TEXT,
				state VARCHAR(1) NOT NULL)";
		$dbh->exec($sql);
	}
	$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	foreach ($productData as $item) {
		$sql = $dbh->prepare("SELECT * FROM Products WHERE sku = :sku");
		$sql->bindParam(':sku', $item[0]);
		$sql->execute();
		if ($sql->rowCount() > 0) {
			$record = $sql->fetch(PDO::FETCH_ASSOC);
			$upd = $dbh->prepare("UPDATE Products SET name=:name, price=:price, images=:images, videos=:videos, pdf=:pdf, features=:features, reviews=:reviews, state=:state 
								  WHERE sku=:sku");
			$state = $item[7] !== $record['reviews'] ? 'R' : 'A';
		} else {
			$upd = $dbh->prepare("INSERT INTO Products (sku, name, price, images, videos, pdf, features, reviews, state)
								  VALUES (:sku, :name, :price, :images, :videos, :pdf, :features, :reviews, :state)");
			$state = 'N';
		}
		$upd->bindParam(':sku', $item[0]);
		$upd->bindParam(':name', $item[1]);
		$upd->bindParam(':price', $item[2]);
		$upd->bindParam(':images', $item[3]);
		$upd->bindParam(':videos', $item[4]);
		$upd->bindParam(':pdf', $item[5]);
		$upd->bindParam(':features', $item[6]);
		$upd->bindParam(':reviews', $item[7]);
		$upd->bindParam(':state', $state);
		$upd->execute();
	}
	$skuList = "'" . implode(', ', array_column($productData, 0)) . "'";
	$skuList = str_replace(", ", "', '", $skuList);
	$sql = $dbh->prepare("SELECT * FROM Products WHERE sku NOT IN (" . $skuList . ")");
	$sql->execute();
	if ($sql->rowCount() > 0) {
		$result = $sql->fetchAll(PDO::FETCH_ASSOC);
		$dbh->beginTransaction();
		foreach ($result as $record) {
			$upd = $dbh->prepare("UPDATE Products SET state=:state WHERE sku=:sku");
			($record['state'] == 'A' || $record['state'] == 'R' || $record['state'] == 'N') ? $state = 'D' : $state = 'O';
			$upd->bindParam(':sku', $record['sku']);
			$upd->bindParam(':state', $state);
			$upd->execute();
		}
		$dbh->commit();
	}
	$dbh = null;
}

function writeCsv($ini) {
	$server = $ini['db_serv'];
	$user = $ini['db_user'];
	$pass = $ini['db_pass'];
	$db = $ini['db_name'];
	
	try {
		$dbh = new PDO("mysql:host=$server;dbname=$db", $user, $pass);
	} catch(PDOException $e) {
		echo "Connection failed.\n" . $e->getMessage();
		return;
	}
	$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	
	$firstRun = false;
	if (!file_exists('products.csv')) { $firstRun = true; }
	
    $csv = fopen('products.csv', 'w');
    fputcsv($csv, ['Product Identifier', 'Product Name', 'Product Price', 'Product Images', 'Product Video', 'Product PDF', 'Product Features', 'Dates of Reviews'], "|");
	$sql = "SELECT * FROM Products WHERE state IN ('A', 'R', 'N')";
	foreach ($dbh->query($sql) as $record) {
		fputcsv($csv, [$record['sku'], $record['name'], $record['price'], $record['images'], 
				$record['videos'], $record['pdf'], $record['features'], $record['reviews']], "|");
	}
	fclose($csv);
	
	if ($firstRun) { $dbh = null; return; }
	
	$csv = fopen("disappeared_products.csv", "w");
	$sql = "SELECT * FROM Products WHERE state='D'";
	foreach ($dbh->query($sql) as $record) {
		fwrite($csv, $record['sku'] . "\r\n");
	}
	fclose($csv);

	$csv = fopen("new_products.csv", "w");
	$sql = "SELECT * FROM Products WHERE state='N'";
	foreach ($dbh->query($sql) as $record) {
		fwrite($csv, $record['sku'] . "\r\n");
	}
	fclose($csv);
	
	$csv = fopen("recently_reviewed_products.csv", "w");
	$sql = "SELECT * FROM Products WHERE state='R'";
	foreach ($dbh->query($sql) as $record) {
		fwrite($csv, $record['sku'] . "\r\n");
	}
	fclose($csv);
	$dbh = null;
}

function sendMail($ini) {
    $email = new PHPMailer();

    $email->setFrom('cjvoodoo@gmail.com', 'Denis Si', 0);
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

if (!file_exists('testing.ini')) {
	echo "ini file does not exist.\n";
	exit();
}
$ini = parse_ini_file('testing0.ini');

$client = new Client();
$parser = new Parser($client);

echo "Getting product urls...";
$parser->getUrls("https://www.carid.com/suspension-systems.html", 2);
echo "done\n";

echo "Getting product data...";
foreach ($parser->getProductUrls() as $urls) {
    $parser->parse($urls);
}
echo "done\n";

$productData = [];
foreach ($parser->getProductData() as $item) {
    $product = new Product();
    $product->setProductData($item);
    $productData[] = $product->getProductData();
}

echo "Writing to database...";
writeDB($ini, $productData);
echo "done\n";

echo "Writing files...";
writeCsv($ini);
echo "done\n";

echo "Sending mail...";
sendMail($ini);
