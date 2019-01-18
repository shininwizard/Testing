<?php
/**
 * @Entity(repositoryClass="ProductRepository")
 * @Table(name="products")
 **/
 class Product {
	/** @Id @Column(type="integer") @GeneratedValue **/
	private $id;
	/** @Column(type="string") **/
    private $sku;
	/** @Column(type="string") **/
    private $name;
	/** @Column(type="string") **/
    private $price;
	/** @Column(type="text", nullable=true) **/
    private $images;
	/** @Column(type="text", nullable=true) **/
    private $videos;
	/** @Column(type="text", nullable=true) **/
    private $pdf;
	/** @Column(type="text", nullable=true) **/
    private $features;
	/** @Column(type="text", nullable=true) **/
    private $reviews;
	/** @Column(type="string"), length=1 **/
	private $state;

	public function getId() { 
		return $this->id;
	}

	public function getSku() {
		return $this->sku;
	}

	public function setSku($sku) {
		$this->sku = $sku;
	}

	public function getName() {
		return $this->name;
	}

	public function setName($name) {
		$this->name = $name;
	}

	public function getPrice() {
		return $this->price;
	}

	public function setPrice($price) {
		$this->price = $price;
	}

	public function getImages() {
		return $this->images;
	}

	public function setImages($images) {
		$this->images = $images;
	}

	public function getVideos() {
		return $this->videos;
	}

	public function setVideos($videos) {
		$this->videos = $videos;
	}

	public function getPdf() {
		return $this->pdf;
	}

	public function setPdf($pdf) {
		$this->pdf = $pdf;
	}

	public function getFeatures() {
		return $this->features;
	}

	public function setFeatures($features) {
		$this->features = $features;
	}

	public function getReviews() {
		return $this->reviews;
	}

	public function setReviews($reviews) {
		$this->reviews = $reviews;
	}

	public function getState() {
		return $this->state;
	}

	public function setState($state) {
		$this->state = $state;
	}
}
