<?php
require  __DIR__.'/vendor/autoload.php';
use Automattic\WooCommerce\Client as Wooclient;

class Zenegal_API_Client
{


    public function __construct()
    {
        $this->http = new GuzzleHttp\Client([
            'base_uri' => getenv('REMOTE_API'),
            'headers' => [
                'X-API-KEY' => getenv('REMOTE_SECRET_KEY')
            ]

        ]);

        $this->wc_api = new Wooclient(
            getenv('WP_SERVER'),
            getenv('WP_CLIENT_KEY'),
            getenv('WP_CLIENT_SECRET'),
            [
                'wp_api' => true,
                'version' => 'wc/v3',
                'headers' => [
                    "Content-Type" => "application/json"
                ]
            ],

        );

        $this->cdn = 'https://cdn.zenegal.store';
        
    }

    public function fetchCategories()
    {
        try {
            $response = $this->http->get('categories');
            $contents = (string) $response->getBody();
            $contents =  (array) json_decode($contents, true)['data'];
            foreach ($contents as $categories) {
                try {

                    unset($categories['id']);
                    $this->wc_api->post('products/categories', $categories);
                } catch (\Exception $e) {
                    var_dump($e->getMessage());
                }
            }
        } catch (\Exception $e) {
            var_dump($e);
        }
    }

    public function getId($item)
    {
        return json_encode($item['id']);
    }

    public function fetchProducts()
    {
        try {
            $contents = $this->getAllProducts(1);
            $contents = array_merge($contents,$this->getAllProducts(2)) ;
            $contents = array_merge($contents,$this->getAllProducts(3)) ;
            echo ('Total products to import:'. count($contents)."\r\n");
            foreach ($contents as $product) {
                $category = $this->getCategoryId($product);
                $newProduct = $this->getProduct($product);
                if (!is_null($newProduct)) {       
                    $wp_product['stock_status'] =  $product['listing']['stock_status']['code'] === 'in_stock' ? 'in_stock' : 'outofstock';
                    $wp_product['slug'] =  $product['listing']['slug'];
                    $wp_product['images'] = [$this->setImageURI( $product['listing']['image'],$product['listing']['name'])];
                    echo ('Updated:'.$product['listing']['name']."\r\n");
                } else {
                    $data = [
                        'name' => $product['listing']['name'],
                        'type' => 'simple',
                        'description' => $product['listing']['name'],
                        'short_description' => $product['listing']['name'],
                        'categories' => [
                            [
                                'id' => $category['id']
                            ]
                        ],
                        'images' => [$this->setImageURI($product['listing']['image'],$product['listing']['name'])],
                        'stock_status' => $product['listing']['stock_status']['code'] === 'in_stock' ? 'instock' : 'outofstock',
                        'slug' => $product['listing']['slug'],
                        ];
                        $this->wc_api->post('products', $data);
                        echo ('Imported:'.$product['listing']['name']."\r\n");
                }
            }
        } catch (\Exception $e) {
            var_dump($e->getMessage());
        }
    }  

    public function setImageURI($images,$name){
        $new_images = [
                'src' => $this->cdn.$images['original'],
                'name' => $name
        ];
        return $new_images;
    }


    public function getAllProducts($category = null)
    {
       echo('start fetch:' .$category . "\r\n");
       try{
        $results = $this->http->get('products/search?limit=10&category='.$category);
        $contents = (string) $results->getBody();
        $contents = (array) json_decode($contents, true);
        $total_pages =  $contents['pages'];
        $products =  $contents['data'];
        $page = 1;
        do{
            $total_pages -= 1;
            $page += 1;
            $results = $this->http->get('products/search?limit=10category='.$category.'&page='.$page);
            $contents = (string) $results->getBody();
            $newProducts = (array) json_decode($contents, true)['data'];
            $products = array_merge($products,$newProducts);

        }while ($total_pages > 1);
        return $products;
       }catch(\Exception $e){
           var_dump($e);
       }
    }

    public function getProduct($wp_product)
    {
        try {
                $products = $this->wc_api->get('products',['slug' =>  $wp_product['listing']['slug']]);
                foreach($products as $product){
                    if($product['slug'] == $wp_product['listing']['slug']){
                        return $product;
                    }
                }
        } catch (\Exception $e) {
            var_dump($e->getMessage());
        }
    }

    public function getCategoryId($product)
    {
        try {
            $category = null;
            $categories = $this->wc_api->get('products/categories');
            foreach ($categories as $key => $category) {
                if ($category['slug'] ==  $this->setCategory($product['listing']['category']['slug'])) {
                    $category = $category;
                    return $category;
                };
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function setCategory($slug){
        switch($slug){
            case 'mens':
                return 'men-t-shirt';
            case 'kids':
                return 'kids-t-shirt';  
            case 'fashion-accessories':
                return 'fashion-accessories';
            case 'accessories':
                return 'accessories';
            default:
                return 'uncategorized';             
        }
    }
}


$import = new Zenegal_API_Client();
$import->fetchProducts();