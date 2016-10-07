<?php

namespace App\Action;

use Slim\Http\Request,
    Slim\Http\Response;

use Facebook;
use Stringy\Stringy;

use Thapp\XmlBuilder\XMLBuilder,
    Thapp\XmlBuilder\Normalizer;

use FileSystemCache;

final class FacebookAction
{
    /** @var $fb Facebook\Facebook */
    private $fb;
    private $user_id;
    private $length = 5;
    private $paths;

    public function __construct($facebook_config, $paths)
    {
        $this->fb = new Facebook\Facebook($facebook_config);
        $this->paths = $paths;
    }

    public function posts(Request $request, Response $response, $args)
    {
        $this->setUserId($args['user-id']);

        if(isset($args['amount']))
        {
            $this->setLength($args['amount']);
        }

        $forceFileCached = isset($request->getQueryParams()['forceFileCached']) ? $request->getQueryParams()['forceFileCached'] : false;

        FileSystemCache::$cacheDir = __DIR__ . '/../../../cache/tmp';
        $key = FileSystemCache::generateCacheKey($args['user-id']);
        $data = FileSystemCache::retrieve($key);

        if($data === false || $forceFileCached == true)
        {
            $facebook_token_path = __DIR__ . '/../../../data/tokens/facebook.tk';
            $accessToken = json_decode(file_get_contents($facebook_token_path));

            $fb = $this->getFb();
            $fb->setDefaultAccessToken($accessToken->token);

            $batch = [
                $fb->request('GET', '/' . $this->getUserId() . '?fields=id, name, engagement, company_overview, mission, picture.type(large){url}, cover.type(large){id, source}'),
                $fb->request('GET', '/' . $this->getUserId() . '/posts?limit=' . $this->getLength() . '&fields=id, object_id, type, created_time, updated_time, name, message, shares, source, attachments{media{image{src}}, target}, likes.limit(5).summary(true){name, picture.type(small){url}}, reactions.limit(5).summary(total_count){name, picture.type(small){url}}, comments.limit(5).summary(true){from{name, picture.type(small){url}}, message}'),
            ];

            /** @var $fb_batch_response /Facebook\FacebookBatchResponse */
            $fb_batch_response = $fb->sendBatchRequest($batch);

            $fb_data_fanpage = json_decode($fb_batch_response->getGraphNode()->getField(0)->getField('body'));
            $fb_data_posts = json_decode($fb_batch_response->getGraphNode()->getField(1)->getField('body'));

            //FOLDER user-id
            $path_uploads = __DIR__ . '/../../../data/uploads/' . $fb_data_fanpage->id . '/';
            if(!file_exists($path_uploads))
            {
                mkdir($path_uploads);
            }

            //IMAGE PAGE COVER
            $img_page_cover_name = 'profile_cover_' . sha1($fb_data_fanpage->cover->source) . '.jpg';
            $img_page_cover_path = $path_uploads . $img_page_cover_name;
            if(!file_exists($img_page_cover_path))
            {
                $content = file_get_contents(str_replace('https://', 'http://', $fb_data_fanpage->cover->source));
                file_put_contents($img_page_cover_path, $content);
            }

            //IMAGE PAGE PICTURE
            $img_page_picture_name = 'profile_picture_' . sha1($fb_data_fanpage->picture->data->url) . '.jpg';
            $img_page_picture_path = $path_uploads . $img_page_picture_name;
            if(!file_exists($img_page_picture_path))
            {
                $content = file_get_contents(str_replace('https://', 'http://', $fb_data_fanpage->picture->data->url));
                file_put_contents($img_page_picture_path, $content);
            }

            $data = array(
                'info' => array(
                    'name' => $fb_data_fanpage->name,
                    'engagement' => array(
                        'count' => $fb_data_fanpage->engagement->count,
                        'sentence' => explode(' ', $fb_data_fanpage->engagement->social_sentence)[0]
                    ),
                    'overview' => array(
                        'cut' => str_replace("\n", ' ', (string) Stringy::create($fb_data_fanpage->company_overview)->safeTruncate(250, '...')),
                        'full' => $fb_data_fanpage->company_overview
                    ),
                    'mission' => array(
                        'cut' => str_replace("\n", ' ', (string) Stringy::create($fb_data_fanpage->mission)->safeTruncate(250, '...')),
                        'full' => $fb_data_fanpage->mission
                    ),
                    'midia' => array(
                        'cover' => $this->getPaths()['upload_path_virtual'] . $fb_data_fanpage->id . '/' . $img_page_cover_name,
                        'profile' => $this->getPaths()['upload_path_virtual'] . $fb_data_fanpage->id . '/'  . $img_page_picture_name,
                    )
                ),
                'feeds' => array()
            );


            foreach($fb_data_posts->data as $i => $item)
            {
                $created = new \DateTime(date('Y-m-d H:i:s', strtotime($item->created_time)));
                $created->setTimezone(new \DateTimeZone('America/Sao_paulo'));

                $updated = new \DateTime(date('Y-m-d H:i:s', strtotime($item->updated_time)));
                $updated->setTimezone(new \DateTimeZone('America/Sao_paulo'));

                //IMAGE FEED
                $img_feed_name = 'feed_image_' . sha1($item->attachments->data[0]->media->image->src) . '.jpg';
                $img_feed_path = $path_uploads . $img_feed_name;
                if(!file_exists($img_feed_path))
                {
                    $content = file_get_contents(str_replace('https://', 'http://', $item->attachments->data[0]->media->image->src));
                    file_put_contents($img_feed_path, $content);
                }

                //VIDEO FEED
                if($item->type == 'video')
                {
                    $video_feed_name = 'feed_video_' . sha1($item->attachments->data[0]->media->image->src) . '.mp4';
                    $video_feed_path = $path_uploads . $video_feed_name;
                    if(!file_exists($video_feed_path))
                    {
                        $content = file_get_contents(str_replace('https://', 'http://', $item->source));
                        file_put_contents($video_feed_path, $content);
                    }
                }

                $data['feeds'][$i] = array(
                    'id' => $item->id,
                    'type' => $item->type,
                    'date' => array(
                        'created' => $created->format('Y-m-d H:i:s'),
                        'updated' => $updated->format('Y-m-d H:i:s')
                    ),
                    'message' => array(
                        'cut' => str_replace("\n", ' ', (string) Stringy::create($item->message)->safeTruncate(250, '...')),
                        'full' => $item->message
                    ),
                    'midia' => array(
                       'image' => $this->getPaths()['upload_path_virtual'] . $fb_data_fanpage->id . '/' . $img_feed_name,
                        'video' => $item->type == 'video' ? $this->getPaths()['upload_path_virtual'] . $fb_data_fanpage->id . '/' . $video_feed_name : ''
                    ),
                    'engagement' => array(
                        'shares' => array(
                            'total' => $item->shares->count,
                        ),
                        'likes' => array(
                            'total' => $item->likes->summary->total_count,
                            'users' => ''
                        ),
                        'reactions' => array(
                            'total' => $item->reactions->summary->total_count,
                            'users' => ''
                        ),
                        'comments' => array(
                            'total' => $item->comments->summary->total_count,
                            'users' => ''
                        ),
                    ),
                );

                foreach($item->likes->data as $user)
                {
                    //IMAGE FEED ENGAGEMENT
                    $img_feed_engagement_name = 'feed_engagement_' . sha1($user->picture->data->url) . '.jpg';
                    $img_feed_engagement_path = $path_uploads . $img_feed_engagement_name;
                    if(!file_exists($img_feed_engagement_path))
                    {
                        $content = file_get_contents(str_replace('https://', 'http://', $user->picture->data->url));
                        file_put_contents($img_feed_engagement_path, $content);
                    }

                    $data['feeds'][$i]['engagement']['likes']['users'][] = array(
                        'name' => $user->name,
                        'picture' => $this->getPaths()['upload_path_virtual'] . $fb_data_fanpage->id . '/' . $img_feed_engagement_name
                    );
                }

                foreach($item->reactions->data as $user)
                {
                    //IMAGE FEED ENGAGEMENT
                    $img_feed_engagement_name = 'feed_engagement_' . sha1($user->picture->data->url) . '.jpg';
                    $img_feed_engagement_path = $path_uploads . $img_feed_engagement_name;
                    if(!file_exists($img_feed_engagement_path))
                    {
                        $content = file_get_contents(str_replace('https://', 'http://', $user->picture->data->url));
                        file_put_contents($img_feed_engagement_path, $content);
                    }

                    $data['feeds'][$i]['engagement']['reactions']['users'][] = array(
                        'name' => $user->name,
                        'picture' => $this->getPaths()['upload_path_virtual'] . $fb_data_fanpage->id . '/' . $img_feed_engagement_name
                    );
                }

                foreach($item->comments->data as $user)
                {
                    //IMAGE FEED ENGAGEMENT
                    $img_feed_engagement_name = 'feed_engagement_' . sha1($user->from->picture->data->url) . '.jpg';
                    $img_feed_engagement_path = $path_uploads . $img_feed_engagement_name;
                    if(!file_exists($img_feed_engagement_path))
                    {
                        $content = file_get_contents(str_replace('https://', 'http://', $user->from->picture->data->url));
                        file_put_contents($img_feed_engagement_path, $content);
                    }

                    $data['feeds'][$i]['engagement']['comments']['users'][] = array(
                        'name' => $user->from->name,
                        'picture' => $this->getPaths()['upload_path_virtual'] . $fb_data_fanpage->id . '/' . $img_feed_engagement_name,
                        'message' => array(
                            'cut' => str_replace("\n", ' ', (string) Stringy::create($user->message)->safeTruncate(250, '...')),
                            'full' => $user->message
                        )
                    );
                }
            }

            FileSystemCache::store($key, $data, 7200);
        }

        $xmlBuilder = new XmlBuilder('root');
        $xmlBuilder->setSingularizer(function ($name) {
            if ('feeds' === $name) {
                return 'feed';
            }
            if ('users' === $name) {
                return 'user';
            }
            return $name;
        });
        $xmlBuilder->load($data);
        $xml_output = $xmlBuilder->createXML(true);

        $response->write($xml_output);
        $response = $response->withHeader('content-type', 'text/xml');
        return $response;
    }

    public function login()
    {
        $helper = $this->getFb()->getRedirectLoginHelper();
        $loginUrl = $helper->getLoginUrl($this->getPaths()['host'] . 'facebook/callback', array());
        echo '<a href="' . htmlspecialchars($loginUrl) . '">Log in with Facebook!</a>';
    }

    public function callback()
    {
        $helper = $this->getFb()->getRedirectLoginHelper();

        try {
            /** @var $accessToken Facebook\Authentication\AccessToken */
            $accessToken = $helper->getAccessToken();
        } catch(Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            echo 'Graph returned an error: ' . $e->getMessage();
            exit;
        } catch(Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            echo 'Facebook SDK returned an error: ' . $e->getMessage();
            exit;
        }

        if (! isset($accessToken)) {
            if ($helper->getError()) {
                header('HTTP/1.0 401 Unauthorized');
                echo "Error: " . $helper->getError() . "\n";
                echo "Error Code: " . $helper->getErrorCode() . "\n";
                echo "Error Reason: " . $helper->getErrorReason() . "\n";
                echo "Error Description: " . $helper->getErrorDescription() . "\n";
            } else {
                header('HTTP/1.0 400 Bad Request');
                echo 'Bad request';
            }
            exit;
        }

        $facebook_token_path = __DIR__ . '/../../../data/tokens/facebook.tk';
        file_put_contents($facebook_token_path, json_encode([
            'token' => $accessToken->getValue(),
            'expiresAt' => $accessToken->getExpiresAt()
        ]));

        // Logged in
        echo '<h3>Access Token</h3>';
        echo '<pre>' . PHP_EOL;
        print_r($accessToken);
        echo '</pre>' . PHP_EOL;
    }

    public function infoAccessToken()
    {
        $facebook_token_path = __DIR__ . '/../../../data/tokens/facebook.tk';
        $accessToken = json_decode(file_get_contents($facebook_token_path), true);

        echo '<pre>' . PHP_EOL;
        print_r($accessToken);
        echo '</pre>' . PHP_EOL;
    }

    /**
     * @return Facebook\Facebook
     */
    public function getFb()
    {
        return $this->fb;
    }

    /**
     * @return mixed
     */
    private function getUserId()
    {
        return $this->user_id;
    }

    /**
     * @param mixed $user_id
     */
    private function setUserId($user_id)
    {
        $this->user_id = $user_id;
    }

    /**
     * @return int
     */
    private function getLength()
    {
        return $this->length;
    }

    /**
     * @param int $length
     */
    private function setLength($length)
    {
        $this->length = $length;
    }

    /**
     * @return mixed
     */
    private function getPaths()
    {
        return $this->paths;
    }

    private function numberUnity($number)
    {
        $unity = '';
        if($number > 999)
        {
            $unity = 'MIL';
        }
        if($number > 999999)
        {
            $unity = 'MILHÃO';
        }
        if($number > 1999999)
        {
            $unity = 'MILHÕES';
        }
        return $unity;
    }

    private function numerShorten($number)
    {
        $shorten = $number;
        if($number > 999)
        {
            $number = number_format($number, 0, '', '.');
            $number = explode('.', $number);

            $shorten = $number[0];
        }

        return $shorten;
    }
}