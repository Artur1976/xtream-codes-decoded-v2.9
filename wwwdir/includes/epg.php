<?php

/**
 * Klasa obsługująca Elektroniczny Przewodnik po Programach (EPG)
 * Odkodowana i wyczyszczona z obfuskacji.
 */
class ipTV_EPG {
    
    public $validEpg = false;
    public $epgSource;
    public $from_CACHE = false;
    public $cacheFile;
    
    /**
     * Konstruktor klasy EPG
     * * @param string $source Źródło EPG (URL lub ścieżka)
     * @param string $epg_id Unikalny identyfikator EPG do cache
     */
    function __construct($source, $epg_id = "") {
        if (empty($source)) {
            return false;
        }
        
        $this->epgSource = $source;
        // Ustalenie ścieżki do pliku cache na podstawie MD5 ze źródła lub ID
        $cache_name = !empty($epg_id) ? md5($epg_id) : md5($source);
        $this->cacheFile = EPG_CACHE_DIR . $cache_name . ".xml";
        
        if (file_exists($this->cacheFile)) {
            $this->from_CACHE = true;
            $this->validEpg = true;
        }
    }
    
    /**
     * Pobiera dane EPG ze źródła lub z cache
     */
    public function getEpgData() {
        if ($this->from_CACHE) {
            return file_get_contents($this->cacheFile);
        }
        
        $data = $this->fetchFromSource($this->epgSource);
        if ($data) {
            $this->saveToCache($data);
            $this->validEpg = true;
            return $data;
        }
        
        return false;
    }
    
    /**
     * Pobiera dane z zewnętrznego serwera EPG
     */
    private function fetchFromSource($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            return $result;
        }
        return false;
    }
    
    /**
     * Zapisuje pobrane dane XML do cache
     */
    private function saveToCache($data) {
        if (!empty($data)) {
            return file_put_contents($this->cacheFile, $data);
        }
        return false;
    }
    
    /**
     * Parsuje dane XMLTV i wyciąga program dla konkretnego kanału
     * * @param string $channel_id Identyfikator kanału w pliku XMLTV
     * @return array Tablica z programem telewizyjnym
     */
    public function parseChannel($channel_id) {
        if (!$this->validEpg) {
            return array();
        }
        
        $xml_content = file_get_contents($this->cacheFile);
        if (empty($xml_content)) return array();
        
        // Wykorzystanie SimpleXML do sparsowania danych
        $xml = simplexml_load_string($xml_content);
        if (!$xml) return array();
        
        $programs = array();
        foreach ($xml->programme as $prog) {
            if ((string)$prog->attributes()->channel == $channel_id) {
                $programs[] = array(
                    'start' => (string)$prog->attributes()->start,
                    'stop'  => (string)$prog->attributes()->stop,
                    'title' => (string)$prog->title,
                    'desc'  => (string)$prog->desc
                );
            }
        }
        return $programs;
    }

    /**
     * Czyści stary cache EPG
     */
    public static function clearOldCache($days = 7) {
        $files = glob(EPG_CACHE_DIR . "*");
        foreach ($files as $file) {
            if (is_file($file) && (time() - filemtime($file) > ($days * 86400))) {
                unlink($file);
            }
        }
    }
}
?>
