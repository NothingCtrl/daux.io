<?php namespace Todaymade\Daux\Format\Confluence;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ParseException;
use GuzzleHttp\Exception\TransferException;

class Api
{

    protected $base_url;
    protected $user;
    protected $pass;

    protected $space;

    public function __construct($base_url, $user, $pass)
    {
        $this->base_url = $base_url;
        $this->user = $user;
        $this->pass = $pass;
    }

    public function setSpace($space_id)
    {
        $this->space = $space_id;
    }

    protected function getClient()
    {
        $options = [
            'base_url' => $this->base_url . 'rest/api/',
            'defaults' => [
                'auth' => [$this->user, $this->pass]
            ]
        ];

        return new Client($options);
    }

    /**
     * The standard error message from guzzle is quite poor in informations,
     * this will give little bit more sense to it and return it
     *
     * @param BadResponseException $e
     * @return BadResponseException
     */
    protected function handleError(BadResponseException $e)
    {
        $request = $e->getRequest();
        $response = $e->getResponse();

        $level = floor($response->getStatusCode() / 100);

        if ($level == '4') {
            $label = 'Client error response';
        } elseif ($level == '5') {
            $label = 'Server error response';
        } else {
            $label = 'Unsuccessful response';
        }

        $message = $label .
            ' [url] ' . $request->getUrl() .
            ' [status code] ' . $response->getStatusCode() .
            ' [message] ';

        try {
            $message .= $response->json()['message'];
        } catch (ParseException $e) {
            $message .= (string) $response->getBody();
        }

        return new BadResponseException($message, $request, $response, $e->getPrevious());
    }

    /**
     * /rest/api/content/{id}/child/{type}
     *
     * @param $rootPage
     * @return mixed
     */
    public function getHierarchy($rootPage)
    {
        try {
            $hierarchy = $this->getClient()->get("content/$rootPage/child/page?expand=version,body.storage")->json();
        } catch (BadResponseException $e) {
            throw $this->handleError($e);
        }

        $children = [];
        foreach ($hierarchy['results'] as $result) {
            $children[$result['title']] = [
                "id" => $result['id'],
                "title" => $result['title'],
                "version" => $result['version']['number'],
                "content" => $result['body']['storage']['value'],
                "children" => $this->getHierarchy($result['id'])
            ];
        }

        return $children;
    }

    public function createPage($parent_id, $title, $content)
    {
        $body = [
            'type' => 'page',
            'space' => ['key' => $this->space],
            'ancestors' => [['type' => 'page', 'id' => $parent_id]],
            'title' => $title,
            'body' => ['storage' => ['value' => $content, 'representation' => 'storage']]
        ];

        try {
            $response = $this->getClient()->post('content', [ 'json' => $body ])->json();
        } catch (BadResponseException $e) {
            throw $this->handleError($e);
        }

        return $response['id'];
    }

    public function updatePage($parent_id, $page_id, $newVersion, $title, $content)
    {
        $body = [
            'type' => 'page',
            'space' => ['key' => $this->space],
            'ancestors' => [['type' => 'page', 'id' => $parent_id]],
            'version' => ['number' => $newVersion, "minorEdit" => false],
            'title' => $title,
            'body' => ['storage' => ['value' => $content, 'representation' => 'storage']]
        ];

        try {
            $this->getClient()->put("content/$page_id", ['json' => $body])->json();
        } catch (BadResponseException $e) {
            throw $this->handleError($e);
        }
    }

    public function deletePage($page_id)
    {
        try {
            return $this->getClient()->delete('content/' . $page_id)->json();
        } catch (BadResponseException $e) {
            throw $this->handleError($e);
        }
    }

    public function uploadAttachment($id, $attachment)
    {
        //get if attachment is uploaded
        try {
            $result = $this->getClient()->get("content/$id/child/attachment?filename=$attachment[filename]")->json();
        } catch (BadResponseException $e) {
            throw $this->handleError($e);
        }

        $url = "content/$id/child/attachment" . (count($result['results'])? "/{$result['results'][0]['id']}/data" : "");

        try {
            $this->getClient()->post(
                $url,
                [
                    'body' => ['file' => fopen($attachment['file']->getPath(), 'r')] ,
                    'headers' => ['X-Atlassian-Token' => 'nocheck'],
                ]
            );
        } catch (BadResponseException $e) {
            throw $this->handleError($e);
        }

        //FIXME :: When doing an update, Confluence does a null pointer exception
    }
}
