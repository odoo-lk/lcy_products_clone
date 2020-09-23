<?php
require  __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Client as Wooclient;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\Dotenv\Dotenv;

class Zenegal_API_Client
{


    public function __construct()
    {

        $dotenv = new Dotenv();
        $dotenv->loadEnv(__DIR__ . '/.env');

        $this->http = new GuzzleClient([
            'base_uri' => $_ENV['REMOTE_API'],
            'headers' => [
                'X-API-KEY' => $_ENV['REMOTE_SECRET_KEY']
            ]

        ]);
        $this->wc_api = new Wooclient(
            $_ENV['WP_SERVER'],
            $_ENV['WP_CLIENT_KEY'],
            $_ENV['WP_CLIENT_SECRET'],
            [
                'wp_api' => true,
                'version' => 'wc/v3',
                'headers' => [
                    "Content-Type" => "application/json"
                ]
            ]
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
            $contents = array_merge($contents, $this->getAllProducts(2));
            $contents = array_merge($contents, $this->getAllProducts(3));
            echo ('Total products to import:' . count($contents) . "\r\n");
            $this->processParallel(array($this, 'process'), $contents, 5);
        } catch (\Exception $e) {
            var_dump($e->getMessage());
        }
    }

    public function process($product)
    {
        $category = $this->getCategoryId($product);
        $newProduct = $this->getProduct($product);
        if (!is_null($newProduct)) {
            $wp_product['stock_status'] =  $product['listing']['stock_status']['code'] == 'in_stock' ? 'instock' : 'outofstock';
            $wp_product['purchasable'] =   $product['listing']['is_purchasable'];
            $wp_product['slug'] =  $product['listing']['slug'];
            $wp_product['attributes'] = $this->getProductOptions($product);
            $wp_product['images'] = [$this->setImageURI($product['listing']['image'], $product['listing']['name'])];
            $wp_product['type'] = 'variable';
            $this->wc_api->put('products/' . $newProduct['id'], $wp_product);
            sleep(2);
            $this->createOrUpdateProductVariant($product, $newProduct);
            echo ('Updated:' . $product['listing']['name'] . "\r\n");
        } else {
            $data = [
                'name' => $product['listing']['name'],
                'type' => 'variable',
                'purchasable' =>   $product['listing']['is_purchasable'],
                'description' => $product['listing']['name'],
                'short_description' => $product['listing']['name'],
                'categories' => [
                    [
                        'id' => $category['id']
                    ]
                ],
                'attributes' => $this->getProductOptions($product),
                'images' => [$this->setImageURI($product['listing']['image'], $product['listing']['name'])],
                'stock_status' => $product['listing']['stock_status']['code'] == 'in_stock' ? 'instock' : 'outofstock',
                'slug' => $product['listing']['slug'],
            ];

            $newProduct = $this->wc_api->post('products', $data);
            $this->createOrUpdateProductVariant($product, $newProduct);
            echo ('Imported:' . $product['listing']['name'] . "\r\n");
        }
    }

    public function getProductOptions($product)
    {
        $data = [
            "option_group_id" => null,
            "options" => []
        ];
        $attributes =  $this->http->post('products/' . $product['listing']['store_based_id'] . '/options', $data);
        $attributes = (string) $attributes->getBody();
        $attributes =  (array) json_decode($attributes, true)['options'];

        $data = array();
        foreach ($attributes as $attribute) {
            $option  = [
                'name' => $attribute['name'],
                'options' => array_column($attribute['options'], 'name'),
                'visible' => true,
                'variation' => true
            ];
            $data[] = $option;
        }
        return $data;
    }

    public function createOrUpdateProductVariant($product, $wp_product)
    {
        foreach ($product['details']['variants'] as $variant) {
            $value = explode('-', $variant['name']);
            $slug = $wp_product['slug'].'-'.$this->slugify($variant['name']);
            $check =  $this->wc_api->get('products/' . $wp_product['id'] . '/variations',['sku' => $slug]);  
            $data = [
                'name' => $variant['name'],
                'description' => $variant['name'],
                'slug' => $slug ,
                'sku' =>  $slug ,
                'purchasable' => $variant['is_purchasable']  ? true : false,
                'stock_status' =>  $variant['is_purchasable']  ? 'instock' : 'outofstock',
                "visible" => true,
                'attributes'    => [
                    [
                        'name'     => 'Size',
                        'option' => trim($value[0]),
                        'visible' => true
                    ],
                ]
            ];

            if(!empty($this->setImageURI($variant['image'], $variant['name']))){
                $data['image'] = $this->setImageURI($variant['image'], $variant['name']);
            }

            if (count($value) == 2) {
                $data['attributes'][] =  [
                    'name'     => 'Colour',
                    'option' =>  trim($value[1]),
                    'visible' => true
                ];
            }
            try {
                if (!$check) {
                    $this->wc_api->post('products/' . $wp_product['id'] . '/variations', $data);
                    echo 'created variant: '.$slug . ' Product : '. $wp_product['name'] . "\r\n";
                } else {
                    $this->wc_api->put('products/' . $wp_product['id'] . '/variations/'.$check[0]['id'], $data);
                    echo 'updated variant: '. $slug. ' Product : '. $wp_product['name'] . "\r\n";

                }
            } catch (\Throwable $th) {
               var_dump($th->getMessage());
            }
        }
    }

    public function getAttributeId($slug)
    {
        try {
            $category = null;
            $categories = $this->wc_api->get('products/attributes');
            foreach ($categories as $key => $category) {
                if ($category['slug'] ==  $slug) {
                    $category = $category;
                    return $category;
                };
            }
        } catch (\Exception $e) {
        }
    }


    public  function slugify($text)
    {
      // replace non letter or digits by -
      $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    
      // transliterate
      $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    
      // remove unwanted characters
      $text = preg_replace('~[^-\w]+~', '', $text);
    
      // trim
      $text = trim($text, '-');
    
      // remove duplicate -
      $text = preg_replace('~-+~', '-', $text);
    
      // lowercase
      $text = strtolower($text);
    
      if (empty($text)) {
        return 'n-a';
      }
    
      return $text;
    }

    public function setImageURI($images, $name)
    {
        $new_images = array();
         if(!empty($images)){
            $new_images = [
                'src' => $images ?  $this->cdn.$images['original'] : ''
            ];
         }
        return $new_images;
    }


    public function getAllProducts($category = null)
    {
        try {
            $results = $this->http->get('products/search?limit=10&source=details.variants&category=' . $category);
            $contents = (string) $results->getBody();
            $contents = (array) json_decode($contents, true);
            $total_pages =  $contents['pages'];
            $products =  $contents['data'];
            $page = 1;
            sleep(2);
            do {
                $total_pages -= 1;
                $page += 1;
                $results = $this->http->get('products/search?limit=10&source=details.variants&category=' . $category . '&page=' . $page);
                $contents = (string) $results->getBody();
                $newProducts = (array) json_decode($contents, true)['data'];
                $products = array_merge($products, $newProducts);
                sleep(2);
            } while ($total_pages > 1);
            return $products;
        } catch (\Exception $e) {
            var_dump($e);
        }
    }

    public function getProduct($wp_product)
    {
        try {
            $products = $this->wc_api->get('products', ['slug' =>  $wp_product['listing']['slug']]);
            foreach ($products as $product) {
                if ($product['slug'] == $wp_product['listing']['slug']) {
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

    public function setCategory($slug)
    {
        switch ($slug) {
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

    public function processParallel($func, array $arr, $procs = 4, $params = [])
    {
        // Break array up into $procs chunks.
        $chunks   = array_chunk($arr, ceil((count($arr) / $procs)));
        $pid      = -1;
        $children = array();
        foreach ($chunks as $items) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                die('could not fork');
            } else if ($pid === 0) {
                // We are the child process. Pass a chunk of items to process.
                echo ('[' . getmypid() . ']This Process executed at' . date("F d, Y h:i:s A") . "\n");
                array_walk($items, $func, $params);
                exit(0);
            } else {
                // We are the parent.
                echo ('[' . getmypid() . ']This Process executed at' . date("F d, Y h:i:s A") . "\n");
                $children[] = $pid;
            }
        }
        // Wait for children to finish.
        foreach ($children as $pid) {
            // We are still the parent.
            pcntl_waitpid($pid, $status);
        }
    }
}


$import = new Zenegal_API_Client();
$import->fetchProducts();
