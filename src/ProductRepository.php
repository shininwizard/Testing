<?php

use Doctrine\ORM\EntityRepository;

class ProductRepository extends EntityRepository {
	public function writeCsv() {
		$firstRun = false;
		if (!file_exists('products.csv')) { $firstRun = true; }
		
		$csv = fopen('products.csv', 'w');
		fputcsv($csv, ['Product Identifier', 'Product Name', 'Product Price', 'Product Images', 'Product Video', 'Product PDF', 'Product Features', 'Dates of Reviews'], "|");
		$query = $this->getEntityManager()->createQuery("SELECT p FROM Product p WHERE p.state IN('N', 'A', 'R')");
		$products = $query->getResult();
		foreach ($products as $record) {
			fputcsv($csv, [$record->getSku(), $record->getName(), $record->getPrice(), $record->getImages(), 
					$record->getVideos(), $record->getPdf(), $record->getFeatures(), $record->getReviews()], "|");
		}
		fclose($csv);
		
		if ($firstRun) { return; }
		
		$csv = fopen("disappeared_products.csv", "w");
		$products = $this->getEntityManager()->getRepository('Product')->findBy(['state' => 'D']);
		foreach ($products as $record) {
			fwrite($csv, $record->getSku() . "\r\n");
		}
		fclose($csv);
		
		$csv = fopen("new_products.csv", "w");
		$products = $this->getEntityManager()->getRepository('Product')->findBy(['state' => 'N']);
		foreach ($products as $record) {
			fwrite($csv, $record->getSku() . "\r\n");
		}
		fclose($csv);
		
		$csv = fopen("recently_reviewed_products.csv", "w");
		$products = $this->getEntityManager()->getRepository('Product')->findBy(['state' => 'R']);
		foreach ($products as $record) {
			fwrite($csv, $record->getSku() . "\r\n");
		}
		fclose($csv);
	}
}