<?php

namespace App\Service;


class CheckHashCachedFile
{
    private $url, $path, $content, $hash, $file_name;

    public function __construct($url, $path = __DIR__ . '/../../../data/uploads/')
    {
        $this->setUrl($url);
        $this->setPath($path);
        $this->setContent(file_get_contents($this->getUrl()));
        $this->setHash(sha1($this->getContent()));
        $this->setFileName($this->getHash() . '.jpg');
    }

    public function checkHashFile()
    {
        if(!file_exists($this->getPath() . $this->getFileName()))
        {
            file_put_contents($this->getPath() . $this->getFileName(), $this->getContent());
        }

        return $this->getFileName();
    }

    /**
     * @return mixed
     */
    private function getUrl()
    {
        return $this->url;
    }

    /**
     * @param mixed $url
     */
    private function setUrl($url)
    {
        $this->url = str_replace('https://', 'http://', $url);
    }

    /**
     * @return mixed
     */
    private function getPath()
    {
        return $this->path;
    }

    /**
     * @param mixed $path
     */
    private function setPath($path)
    {
        $this->path = $path;
    }

    /**
     * @return mixed
     */
    private function getContent()
    {
        return $this->content;
    }

    /**
     * @param mixed $content
     */
    private function setContent($content)
    {
        $this->content = $content;
    }

    /**
     * @return mixed
     */
    private function getHash()
    {
        return $this->hash;
    }

    /**
     * @param mixed $hash
     */
    private function setHash($hash)
    {
        $this->hash = $hash;
    }

    /**
     * @return mixed
     */
    private function getFileName()
    {
        return $this->file_name;
    }

    /**
     * @param mixed $file_name
     */
    private function setFileName($file_name)
    {
        $this->file_name = $file_name;
    }
}