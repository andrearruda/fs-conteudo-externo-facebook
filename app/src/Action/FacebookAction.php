<?php

namespace App\Action;

use Slim\Http\Request;
use Slim\Http\Response;

use App\Service\CheckHashCachedFile;

use Facebook;
use Stringy\Stringy;

use Thapp\XmlBuilder\XMLBuilder;
use Thapp\XmlBuilder\Normalizer;

use FileSystemCache;

final class FacebookAction
{
    private $username;
    private $length = 5;
    private $acessToken = 'EAAPfK6SRjpgBAEvFvUr4y9GjQZBJLVCvXO0h7hBtieZBmU383zKyB9qqNaf4svJA7lc2OKQwnZBT3FfTgTiPBZA8JV2TePFANKirfXJSnwVhBI4ZCvyHJtXwR9CTZA1ZBxwkPx8IR4uuMZAXKa4ufLzLhDrbzUoZCgZAMZD';

    public function __invoke(Request $request, Response $response, $args)
    {
        $forceFileCached = isset($request->getQueryParams()['forceFileCached']) ? $request->getQueryParams()['forceFileCached'] : false;

        FileSystemCache::$cacheDir = __DIR__ . '/../../../cache/tmp';
        $key = FileSystemCache::generateCacheKey('cache', $args['username']);
        $data = FileSystemCache::retrieve($key);

        if($data === false || $forceFileCached == true)
        {
            $this->setUsername($args['username']);
            if(isset($args['amount']))
            {
                $this->setLength($args['amount']);
            }

            $fb = new Facebook\Facebook([
                'app_id' => '1089803467722392',
                'app_secret' => 'f40c536eca0b3db7a22c3fa8f373d873',
                'default_graph_version' => 'v2.7',
                'default_access_token' => $this->getAcessToken()
            ]);

            $batch = [
                $fb->request('GET', '/' . $this->getUsername() . '?fields=id, name, engagement, company_overview, mission, picture.type(large){url}, cover.type(large){id, source}'),
                $fb->request('GET', '/' . $this->getUsername() . '/posts?limit=' . $this->getLength() . '&fields=id, object_id, type, created_time, updated_time, name, message, shares, source, attachments{media{image{src}}, target}, likes.limit(5).summary(true){name, picture.type(large){url}}, reactions.limit(5).summary(total_count){name, picture.type(large){url}}, comments.limit(5).summary(true){from{name, picture.type(large){url}}, message}'),
            ];

            /** @var $fb_batch_response /Facebook\FacebookBatchResponse */
            $fb_batch_response = $fb->sendBatchRequest($batch);

            $fb_data_fanpage = json_decode($fb_batch_response->getGraphNode()->getField(0)->getField('body'));
            $fb_data_posts = json_decode($fb_batch_response->getGraphNode()->getField(1)->getField('body'));

            //IMAGE PAGE
            $img_page_cover_name = $fb_data_fanpage->cover->id . '.jpg';
            $img_page_cover_path = __DIR__ . '/../../../data/uploads/' . $img_page_cover_name;
            if(!file_exists($img_page_cover_path))
            {
                $content = file_get_contents(str_replace('https://', 'http://', $fb_data_fanpage->cover->source));
                file_put_contents($img_page_cover_path, $content);
            }

            $data = array(
                'info' => array(
                    'date' => array(
                        'created' => date('Y-m-d H:i:s'),
                    ),
                    'id' => $fb_data_fanpage->id,
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
                        'picture' => 'http://' . $_SERVER['HTTP_HOST'] . '/facebook/v2/data/uploads/' . (new CheckHashCachedFile($fb_data_fanpage->picture->data->url))->checkHashFile(),
                        'cover' => 'http://' . $_SERVER['HTTP_HOST'] . '/facebook/v2/data/uploads/' . $img_page_cover_name
                    )
                ),
                'itens' => array()
            );

            foreach($fb_data_posts->data as $i => $item)
            {
                $created = new \DateTime(date('Y-m-d H:i:s', strtotime($item->created_time)));
                $created->setTimezone(new \DateTimeZone('America/Sao_paulo'));

                $updated = new \DateTime(date('Y-m-d H:i:s', strtotime($item->updated_time)));
                $updated->setTimezone(new \DateTimeZone('America/Sao_paulo'));

                if($item->type == 'link')
                {
                    $url = parse_url($item->attachments->data[0]->media->image->src);
                    parse_str($url['query'], $query);

                    $image = isset($query['url']) ? $query['url'] : $item->attachments->data[0]->media->image->src;
                }
                else
                {
                    $image = $item->attachments->data[0]->media->image->src;
                }

                $data['itens'][$i] = array(
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
                        'image' => $image,
                        'video' => $item->type == 'video' ? $item->source : ''
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
                    $data['itens'][$i]['engagement']['likes']['users'][] = array(
                        'name' => $user->name,
                        'picture' => $user->picture->data->url
                    );
                }

                foreach($item->reactions->data as $user)
                {
                    $data['itens'][$i]['engagement']['reactions']['users'][] = array(
                        'name' => $user->name,
                        'picture' => $user->picture->data->url
                    );
                }

                foreach($item->comments->data as $user)
                {
                    $data['itens'][$i]['engagement']['comments']['users'][] = array(
                        'name' => $user->from->name,
                        'picture' => $user->from->picture->data->url,
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
            if ('itens' === $name) {
                return 'item';
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

    /**
     * @return mixed
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @param mixed $username
     */
    public function setUsername($username)
    {
        $this->username = $username;
    }

    /**
     * @return int
     */
    public function getLength()
    {
        return $this->length;
    }

    /**
     * @param int $length
     */
    public function setLength($length)
    {
        $this->length = (int) $length;
    }

    /**
     * @return string
     */
    public function getAcessToken()
    {
        return $this->acessToken;
    }

    /**
     * @param string $acessToken
     */
    public function setAcessToken($acessToken)
    {
        $this->acessToken = $acessToken;
    }
}