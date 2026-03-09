<?php

class ipTV_stream
{
    public static $ipTV_db;
    
    // Naprawiona nazwa funkcji (wcześniej brakowało nazwy)
    static function clearStreamCache($sources)
    {
        if (empty($sources)) {
            return;
        }
        foreach ($sources as $source) {
            if (file_exists(STREAMS_PATH . md5($source))) {
                unlink(STREAMS_PATH . md5($source));
            }
        }
    }
    
    static function createChannel($stream_id)
    {
        self::$ipTV_db->query('
                SELECT * FROM `streams` t1 
                LEFT JOIN `transcoding_profiles` t3 ON t1.transcode_profile_id = t3.profile_id
                WHERE t1.`id` = \'%d\'', $stream_id);
        $stream = self::$ipTV_db->get_row();
        $stream['cchannel_rsources'] = json_decode($stream['cchannel_rsources'], true);
        $stream['stream_source'] = json_decode($stream['stream_source'], true);
        $stream['pids_create_channel'] = json_decode($stream['pids_create_channel'], true);
        $stream['transcode_attributes'] = json_decode($stream['profile_options'], true);
        
        if (!array_key_exists('-acodec', $stream['transcode_attributes'])) {
            $stream['transcode_attributes']['-acodec'] = 'copy';
        }
        if (!array_key_exists('-vcodec', $stream['transcode_attributes'])) {
            $stream['transcode_attributes']['-vcodec'] = 'copy';
        }
        
        $ffmpegCommand = FFMPEG_PATH . ' -fflags +genpts -async 1 -y -nostdin -hide_banner -loglevel quiet -i "{INPUT}" ';
        $ffmpegCommand .= implode(' ', self::buildFFmpegArgs($stream['transcode_attributes'])) . ' ';
        $ffmpegCommand .= '-strict -2 -mpegts_flags +initial_discontinuity -f mpegts "' . CREATED_CHANNELS . $stream_id . '_{INPUT_MD5}.ts" >/dev/null 2>/dev/null & jobs -p';
        
        $result = array_diff($stream['stream_source'], $stream['cchannel_rsources']);
        $json_string_data = '';
        foreach ($stream['stream_source'] as $source) {
            $json_string_data .= 'file \'' . CREATED_CHANNELS . $stream_id . '_' . md5($source) . '.ts\'';
        }
        $json_string_data = base64_encode($json_string_data);
        
        if ((!empty($result) || $stream['stream_source'] !== $stream['cchannel_rsources'])) {
            foreach ($result as $source) {
                $stream['pids_create_channel'][] = ipTV_servers::RunCommandServer($stream['created_channel_location'], str_ireplace(array('{INPUT}', '{INPUT_MD5}'), array($source, md5($source)), $ffmpegCommand), 'raw')[$stream['created_channel_location']];
            }
            self::$ipTV_db->query('UPDATE `streams` SET pids_create_channel = \'%s\',`cchannel_rsources` = \'%s\' WHERE `id` = \'%d\'', json_encode($stream['pids_create_channel']), json_encode($stream['stream_source']), $stream_id);
            ipTV_servers::RunCommandServer($stream['created_channel_location'], "echo {$json_string_data} | base64 --decode > \"" . CREATED_CHANNELS . $stream_id . '_.list"', 'raw');
            return 1;
        } else if (!empty($stream['pids_create_channel'])) {
            foreach ($stream['pids_create_channel'] as $key => $pid) {
                if (!ipTV_servers::PidsChannels($stream['created_channel_location'], $pid, FFMPEG_PATH)) {
                    unset($stream['pids_create_channel'][$key]);
                }
            }
            self::$ipTV_db->query('UPDATE `streams` SET pids_create_channel = \'%s\' WHERE `id` = \'%d\'', json_encode($stream['pids_create_channel']), $stream_id);
            return empty($stream['pids_create_channel']) ? 2 : 1;
        } 
    
        return 2;    
    }
    
    static function analyzeStream($stream_url, $server_id, $ffmpeg_args = array(), $prefix_cmd = '')
    {
        $stream_max_analyze = abs(intval(ipTV_lib::$settings['stream_max_analyze']));
        $probesize = abs(intval(ipTV_lib::$settings['probesize']));
        $timeout = intval($stream_max_analyze / 1000000) + 5;
        $command = "{$prefix_cmd}/usr/bin/timeout {$timeout}s " . FFPROBE_PATH . " -probesize {$probesize} -analyzeduration {$stream_max_analyze} " . implode(' ', $ffmpeg_args) . " -i \"{$stream_url}\" -v quiet -print_format json -show_streams -show_format";
        $result = ipTV_servers::RunCommandServer($server_id, $command, 'raw', $timeout * 2, $timeout * 2);
        return self::formatProbeData(json_decode($result[$server_id], true));
    }
    
    public static function formatProbeData($probe_data)
    {
        if (!empty($probe_data)) {
            if (!empty($probe_data['codecs'])) {
                return $probe_data;
            }
            $output = array();
            $output['codecs']['video'] = '';
            $output['codecs']['audio'] = '';
            $output['container'] = $probe_data['format']['format_name'];
            $output['filename'] = $probe_data['format']['filename'];
            $output['bitrate'] = !empty($probe_data['format']['bit_rate']) ? $probe_data['format']['bit_rate'] : null;
            $output['of_duration'] = !empty($probe_data['format']['duration']) ? $probe_data['format']['duration'] : 'N/A';
            $output['duration'] = !empty($probe_data['format']['duration']) ? gmdate('H:i:s', intval($probe_data['format']['duration'])) : 'N/A';
            foreach ($probe_data['streams'] as $stream_info) {
                if (!isset($stream_info['codec_type'])) {
                    continue;
                }
                if ($stream_info['codec_type'] != 'audio' && $stream_info['codec_type'] != 'video') {
                    continue;
                }
                $output['codecs'][$stream_info['codec_type']] = $stream_info;
            }
            return $output;
        }
        return false;
    }
    
    static function stopStream($stream_id, $reset_stream_sys = false)
    {
        if (file_exists("/home/xtreamcodes/iptv_xtream_codes/streams/{$stream_id}.monitor")) {
            $monitor_pid = intval(file_get_contents("/home/xtreamcodes/iptv_xtream_codes/streams/{$stream_id}.monitor"));
            if (self::checkProcessCmd($monitor_pid, "XtreamCodes[{$stream_id}]")) {
                posix_kill($monitor_pid, 9);
            }
        }
        if (file_exists(STREAMS_PATH . $stream_id . '_.pid')) {
            $pid = intval(file_get_contents(STREAMS_PATH . $stream_id . '_.pid'));
            if (self::checkProcessCmd($pid, "{$stream_id}_.m3u8")) {
                posix_kill($pid, 9);
            }
        }
        shell_exec('rm -f ' . STREAMS_PATH . $stream_id . '_*');
        if ($reset_stream_sys) {
            shell_exec('rm -f ' . DELAY_STREAM . $stream_id . '_*');
            self::$ipTV_db->query('UPDATE `streams_sys` SET `bitrate` = NULL,`current_source` = NULL,`to_analyze` = 0,`pid` = NULL,`stream_started` = NULL,`stream_info` = NULL,`stream_status` = 0,`monitor_pid` = NULL WHERE `stream_id` = \'%d\' AND `server_id` = \'%d\'', $stream_id, SERVER_ID);
        }
    }
    
    static function checkProcessCmd($pid, $search)
    {
        if (file_exists('/proc/' . $pid)) {
            $value = trim(file_get_contents("/proc/{$pid}/cmdline"));
            if (stristr($value, $search)) {
                return true;
            }
        }
        return false;
    }
    
    static function startStream($stream_id, $delay_minutes = 0)
    {
        $stream_lock_file = STREAMS_PATH . $stream_id . '.lock';
        $fp = fopen($stream_lock_file, 'a+');
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            $delay_minutes = intval($delay_minutes);
            shell_exec(PHP_BIN . ' ' . TOOLS_PATH . "stream_monitor.php {$stream_id} {$delay_minutes} >/dev/null 2>/dev/null &");
            usleep(300);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    
    static function stopVODstream($stream_id)
    {
        if (file_exists(MOVIES_PATH . $stream_id . '_.pid')) {
            $pid = (int) file_get_contents(MOVIES_PATH . $stream_id . '_.pid');
            posix_kill($pid, 9);
        }
        shell_exec('rm -f ' . MOVIES_PATH . $stream_id . '.*');
        self::$ipTV_db->query('UPDATE `streams_sys` SET `bitrate` = NULL,`current_source` = NULL,`to_analyze` = 0,`pid` = NULL,`stream_started` = NULL,`stream_info` = NULL,`stream_status` = 0 WHERE `stream_id` = \'%d\' AND `server_id` = \'%d\'', $stream_id, SERVER_ID);
    }
    
    static function startVODstream($stream_id)
    {
        $stream = array();
        self::$ipTV_db->query('SELECT * FROM `streams` t1 
                               INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t2.live = 0
                               LEFT JOIN `transcoding_profiles` t4 ON t1.transcode_profile_id = t4.profile_id 
                               WHERE t1.direct_source = 0 AND t1.id = \'%d\'', $stream_id);
        if (self::$ipTV_db->num_rows() <= 0) {
            return false;
        }
        $stream['stream_info'] = self::$ipTV_db->get_row();
        $target_containers = json_decode($stream['stream_info']['target_container'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $stream['stream_info']['target_container'] = $target_containers;
        } else {
            $stream['stream_info']['target_container'] = array($stream['stream_info']['target_container']);
        }
        self::$ipTV_db->query('SELECT * FROM `streams_sys` WHERE stream_id  = \'%d\' AND `server_id` = \'%d\'', $stream_id, SERVER_ID);
        if (self::$ipTV_db->num_rows() <= 0) {
            return false;
        }
        $stream['server_info'] = self::$ipTV_db->get_row();
        self::$ipTV_db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = \'%d\' AND t1.argument_id = t2.id', $stream_id);
        $stream['stream_arguments'] = self::$ipTV_db->get_rows();
        $stream_source = urldecode(json_decode($stream['stream_info']['stream_source'], true)[0]);
        
        if (substr($stream_source, 0, 2) == 's:') {
            $stream_parts = explode(':', $stream_source, 3);
            $stream_server_id = $stream_parts[1];
            if ($stream_server_id != SERVER_ID) {
                $stream_url = ipTV_lib::$StreamingServers[$stream_server_id]['api_url'] . '&action=getFile&filename=' . urlencode($stream_parts[2]);
            } else {
                $stream_url = $stream_parts[2];
            }
            $server_protocol = null;
        } else {
            $server_protocol = substr($stream_source, 0, strpos($stream_source, '://'));
            $stream_url = str_replace(' ', '%20', $stream_source);
            $fetch_options = implode(' ', self::buildStreamArguments($stream['stream_arguments'], $server_protocol, 'fetch'));
        }

        if (!(isset($stream_server_id) && $stream_server_id == SERVER_ID && $stream['stream_info']['movie_symlink'] == 1)) {
            $subtitles = json_decode($stream['stream_info']['movie_subtitles'], true);
            $subs_inputs = '';
            $index = 0;

            while ($index < count($subtitles['files'])) {
                $sub_file = urldecode($subtitles['files'][$index]);
                $sub_charset = $subtitles['charset'][$index];
                if ($subtitles['location'] == SERVER_ID) {
                    $subs_inputs .= "-sub_charenc \"{$sub_charset}\" -i \"{$sub_file}\" ";
                } else {
                    $subs_inputs .= "-sub_charenc \"{$sub_charset}\" -i \"" . ipTV_lib::$StreamingServers[$subtitles['location']]['api_url'] . '&action=getFile&filename=' . urlencode($sub_file) . '" ';
                }
                $index++;
            }

            $subs_maps = '';
            $index = 0;

            while ($index < count($subtitles['files'])) {
                $subs_maps .= '-map ' . ($index + 1) . " -metadata:s:s:{$index} title={$subtitles['names'][$index]} -metadata:s:s:{$index} language={$subtitles['names'][$index]} ";
                $index++;
            }

            $ffmpeg_cmd = FFMPEG_PATH . " -y -nostdin -hide_banner -loglevel warning -err_detect ignore_err {FETCH_OPTIONS} -fflags +genpts -async 1 {READ_NATIVE} -i \"{STREAM_SOURCE}\" {$subs_inputs}";
            $read_native = '';
            if ($stream['stream_info']['read_native'] == 1) {
                $read_native = '-re';
            }
            if ($stream['stream_info']['enable_transcode'] == 1) {
                if ($stream['stream_info']['transcode_profile_id'] == -1) {
                    $stream['stream_info']['transcode_attributes'] = array_merge(self::buildStreamArguments($stream['stream_arguments'], $server_protocol, 'transcode'), json_decode($stream['stream_info']['transcode_attributes'], true));
                } else {
                    $stream['stream_info']['transcode_attributes'] = json_decode($stream['stream_info']['profile_options'], true);
                }
            } else {
                $stream['stream_info']['transcode_attributes'] = array();
            }
            $map = '-map 0 -copy_unknown ';
            if (empty($stream['stream_info']['custom_map'])) {
                $map = $stream['stream_info']['custom_map'] . ' -copy_unknown ';
            } else if ($stream['stream_info']['remove_subtitles'] == 1) {
                $map = '-map 0:a -map 0:v';
            }

            if (!array_key_exists('-acodec', $stream['stream_info']['transcode_attributes'])) {
                $stream['stream_info']['transcode_attributes']['-acodec'] = 'copy';
            }
            if (!array_key_exists('-vcodec', $stream['stream_info']['transcode_attributes'])) {
                $stream['stream_info']['transcode_attributes']['-vcodec'] = 'copy';
            }
            $outputs = array();
            foreach ($stream['stream_info']['target_container'] as $container_priority) {
                $outputs[$container_priority] = "-movflags +faststart -dn {$map} -ignore_unknown {$subs_maps} " . MOVIES_PATH . $stream_id . '.' . $container_priority . ' ';
            }
            foreach ($outputs as $output_key => $output_str) {
                if (($output_key == 'mp4')) { 
                    $stream['stream_info']['transcode_attributes']['-scodec'] = 'mov_text';
                } else if ($output_key == 'mkv') {
                    $stream['stream_info']['transcode_attributes']['-scodec'] = 'srt';
                } else {
                    $stream['stream_info']['transcode_attributes']['-scodec'] = 'copy';
                }
                $ffmpeg_cmd .= implode(' ', self::buildFFmpegArgs($stream['stream_info']['transcode_attributes'])) . ' ';
                $ffmpeg_cmd .= $output_str;
            }
            
            $ffmpeg_cmd .= ' >/dev/null 2>' . MOVIES_PATH . $stream_id . '.errors & echo $! > ' . MOVIES_PATH . $stream_id . '_.pid';
            $ffmpeg_cmd = str_replace(array('{FETCH_OPTIONS}', '{STREAM_SOURCE}', '{READ_NATIVE}'), array(empty($fetch_options) ? '' : $fetch_options, $stream_url, empty($stream['stream_info']['custom_ffmpeg']) ? $read_native : ''), $ffmpeg_cmd);
            $ffmpeg_cmd = "ln -s \"{$stream_url}\" " . MOVIES_PATH . $stream_id . '.' . pathinfo($stream_url, PATHINFO_EXTENSION) . ' >/dev/null 2>/dev/null & echo $! > ' . MOVIES_PATH . $stream_id . '_.pid';
            
            shell_exec($ffmpeg_cmd);
            file_put_contents('/tmp/commands', $ffmpeg_cmd . '', FILE_APPEND);
            $pid = intval(file_get_contents(MOVIES_PATH . $stream_id . '_.pid'));
            self::$ipTV_db->query('UPDATE `streams_sys` SET `to_analyze` = 1,`stream_started` = \'%d\',`stream_status` = 0,`pid` = \'%d\' WHERE `stream_id` = \'%d\' AND `server_id` = \'%d\'', time(), $pid, $stream_id, SERVER_ID);
            return $pid;
        }
    }
    
    static function startLiveStream($stream_id, &$restart_count, $priority_source = null)
    {
        ++$restart_count;
        if (file_exists(STREAMS_PATH . $stream_id . '_.pid')) {
            unlink(STREAMS_PATH . $stream_id . '_.pid');
        }
        $stream = array();
        self::$ipTV_db->query('SELECT * FROM `streams` t1 
                               INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t2.live = 1
                               LEFT JOIN `transcoding_profiles` t4 ON t1.transcode_profile_id = t4.profile_id 
                               WHERE t1.direct_source = 0 AND t1.id = \'%d\'', $stream_id);
        if (self::$ipTV_db->num_rows() <= 0) {
            return false;
        }
        $stream['stream_info'] = self::$ipTV_db->get_row();
        self::$ipTV_db->query('SELECT * FROM `streams_sys` WHERE stream_id  = \'%d\' AND `server_id` = \'%d\'', $stream_id, SERVER_ID);
        if (self::$ipTV_db->num_rows() <= 0) {
            return false;
        }
        $stream['server_info'] = self::$ipTV_db->get_row();
        self::$ipTV_db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = \'%d\' AND t1.argument_id = t2.id', $stream_id);
        $stream['stream_arguments'] = self::$ipTV_db->get_rows();
        
        if ($stream['server_info']['on_demand'] == 1) {
            $probesize = $stream['stream_info']['probesize_ondemand'];
            $stream_max_analyze = '10000000';
        } else {
            $stream_max_analyze = abs(intval(ipTV_lib::$settings['stream_max_analyze']));
            $probesize = abs(intval(ipTV_lib::$settings['probesize']));
        }
        $timeout_seconds = intval($stream_max_analyze / 1000000) + 7;
        $ffprobe_cmd = "/usr/bin/timeout {$timeout_seconds}s " . FFPROBE_PATH . " {FETCH_OPTIONS} -probesize {$probesize} -analyzeduration {$stream_max_analyze} {CONCAT} -i \"{STREAM_SOURCE}\" -v quiet -print_format json -show_streams -show_format";
        $fetch_options = array();
        if ($stream['server_info']['parent_id'] == 0) {
            $sources = $stream['stream_info']['type_key'] == 'created_live' ? array(CREATED_CHANNELS . $stream_id . '_.list') : json_decode($stream['stream_info']['stream_source'], true);
        } else {
            $sources = array(ipTV_lib::$StreamingServers[$stream['server_info']['parent_id']]['site_url_ip'] . 'streaming/admin_live.php?stream=' . $stream_id . '&password=' . ipTV_lib::$settings['live_streaming_pass'] . '&extension=ts');
        }
        if (count($sources) > 0) {
            if (empty($priority_source)) {
                if (ipTV_lib::$settings['priority_backup'] != 1) {
                     $sources = array($priority_source);
                } else if (!empty($stream['server_info']['current_source'])) {
                    $k = array_search($stream['server_info']['current_source'], $sources);
                    if ($k !== false) {
                        $index = 0;
                        while ($index <= $k) {
                            $tmp_source = $sources[$index];
                            unset($sources[$index]);
                            array_push($sources, $tmp_source);
                            $index++;
                        }
                        $sources = array_values($sources);
                    }
                }

                $use_cache = $restart_count <= RESTART_TAKE_CACHE ? true : false;
                if (!$use_cache) {
                    self::clearStreamCache($sources); // Naprawione wywołanie
                }
                foreach ($sources as $source) {
                    $stream_source = self::ParseStreamURL($source);
                    $server_protocol = strtolower(substr($stream_source, 0, strpos($stream_source, '://')));
                    $fetch_options = implode(' ', self::buildStreamArguments($stream['stream_arguments'], $server_protocol, 'fetch'));
                    if ($use_cache && file_exists(STREAMS_PATH . md5($stream_source))) {
                        $probe_json = json_decode(file_get_contents(STREAMS_PATH . md5($stream_source)), true);
                        break;
                    }
                    $probe_json = json_decode(shell_exec(str_replace(array('{FETCH_OPTIONS}', '{CONCAT}', '{STREAM_SOURCE}'), array($fetch_options, $stream['stream_info']['type_key'] == 'created_live' && $stream['server_info']['parent_id'] == 0 ? '-safe 0 -f concat' : '', $stream_source), $ffprobe_cmd)), true);
                    if (!empty($probe_json)) {
                        break;
                    }
                }
                if (empty($probe_json)) {
                    if ($stream['server_info']['stream_status'] == 0 || $stream['server_info']['to_analyze'] == 1 || $stream['server_info']['pid'] != -1) {
                        self::$ipTV_db->query('UPDATE `streams_sys` SET `progress_info` = \'\',`to_analyze` = 0,`pid` = -1,`stream_status` = 1 WHERE `server_id` = \'%d\' AND `stream_id` = \'%d\'', SERVER_ID, $stream_id);
                    }
                    return 0;
                }
                if (!$use_cache) {
                    file_put_contents(STREAMS_PATH . md5($stream_source), json_encode($probe_json));
                }
                $probe_json = self::formatProbeData($probe_json);
                $external_push = json_decode($stream['stream_info']['external_push'], true);
                $progress = 'http://127.0.0.1:' . ipTV_lib::$StreamingServers[SERVER_ID]['http_broadcast_port'] . "/progress.php?stream_id={$stream_id}";
                
                if (empty($stream['stream_info']['custom_ffmpeg'])) {
                    $ffmpeg_cmd = FFMPEG_PATH . " -y -nostdin -hide_banner -loglevel warning -err_detect ignore_err {FETCH_OPTIONS} {GEN_PTS} {READ_NATIVE} -probesize {$probesize} -analyzeduration {$stream_max_analyze} -progress \"{$progress}\" {CONCAT} -i \"{STREAM_SOURCE}\" ";
                    if (($stream['stream_info']['stream_all'] == 1)) {
                        $map = '-map 0 -copy_unknown ';
                    } else if (empty($stream['stream_info']['custom_map'])) {
                        $map = $stream['stream_info']['custom_map'] . ' -copy_unknown ';
                    }
                    if ($stream['stream_info']['type_key'] == 'radio_streams') {
                        $map = '-map 0:a? ';
                    } else {
                        $map = '';
                    }
                    if (($stream['stream_info']['gen_timestamps'] == 1 || empty($server_protocol)) && $stream['stream_info']['type_key'] != 'created_live') {
                        $gen_pts = '-fflags +genpts -async 1';
                    } else {
                        $gen_pts = '-nofix_dts -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0';
                    }
                    $read_native = '';
                    if ($stream['server_info']['parent_id'] == 0 && ($stream['stream_info']['read_native'] == 1 or stristr($probe_json['container'], 'hls') or empty($server_protocol) or stristr($probe_json['container'], 'mp4') or stristr($probe_json['container'], 'matroska'))) {
                        $read_native = '-re';
                    }
                    if ($stream['server_info']['parent_id'] == 0 and $stream['stream_info']['enable_transcode'] == 1 and $stream['stream_info']['type_key'] != 'created_live') {
                        if ($stream['stream_info']['transcode_profile_id'] == -1) {
                            $stream['stream_info']['transcode_attributes'] = array_merge(self::buildStreamArguments($stream['stream_arguments'], $server_protocol, 'transcode'), json_decode($stream['stream_info']['transcode_attributes'], true));
                        } else {
                            $stream['stream_info']['transcode_attributes'] = json_decode($stream['stream_info']['profile_options'], true);
                        }
                    } else {
                        $stream['stream_info']['transcode_attributes'] = array();
                    }
                    if (!array_key_exists('-acodec', $stream['stream_info']['transcode_attributes'])) {
                        $stream['stream_info']['transcode_attributes']['-acodec'] = 'copy';
                    }
                    if (!array_key_exists('-vcodec', $stream['stream_info']['transcode_attributes'])) {
                        $stream['stream_info']['transcode_attributes']['-vcodec'] = 'copy';
                    }
                    if (!array_key_exists('-scodec', $stream['stream_info']['transcode_attributes'])) {
                        $stream['stream_info']['transcode_attributes']['-scodec'] = 'copy';
                    }
                    $outputs = array();
                    $outputs['mpegts'][] = '{MAP} -individual_header_trailer 0 -f segment -segment_format mpegts -segment_time ' . ipTV_lib::$SegmentsSettings['seg_time'] . ' -segment_list_size ' . ipTV_lib::$SegmentsSettings['seg_list_size'] . ' -segment_format_options "mpegts_flags=+initial_discontinuity:mpegts_copyts=1" -segment_list_type m3u8 -segment_list_flags +live+delete -segment_list "' . STREAMS_PATH . $stream_id . '_.m3u8" "' . STREAMS_PATH . $stream_id . '_%d.ts" ';
                    if ($stream['stream_info']['rtmp_output'] == 1) {
                        $outputs['flv'][] = '{MAP} {AAC_FILTER} -f flv rtmp://127.0.0.1:' . ipTV_lib::$StreamingServers[$stream['server_info']['server_id']]['rtmp_port'] . "/live/{$stream_id} ";
                    }
                    if (!empty($external_push[SERVER_ID])) {
                        foreach ($external_push[SERVER_ID] as $push_url) {
                            $outputs['flv'][] = "{MAP} {AAC_FILTER} -f flv \"{$push_url}\" ";
                        }
                    }
                    $delay_start = 0;
                    if (!($stream['stream_info']['delay_minutes'] > 0 && $stream['server_info']['parent_id'] == 0)) {
                        foreach ($outputs as $output_key => $output_group) {
                            foreach ($output_group as $output_str) {
                                $ffmpeg_cmd .= implode(' ', self::buildFFmpegArgs($stream['stream_info']['transcode_attributes'])) . ' ';
                                $ffmpeg_cmd .= $output_str;
                            }
                        }
                    } else {
                        $segment_start_number = 0;
                        if (file_exists(DELAY_STREAM . $stream_id . '_.m3u8')) {
                            $file = file(DELAY_STREAM . $stream_id . '_.m3u8');
                            if (stristr($file[count($file) - 1], $stream_id . '_')) {
                                if (preg_match('/\\_(.*?)\\.ts/', $file[count($file) - 1], $matches)) {
                                    $segment_start_number = intval($matches[1]) + 1;
                                }
                            } else {
                                if (preg_match('/\\_(.*?)\\.ts/', $file[count($file) - 2], $matches)) {
                                    $segment_start_number = intval($matches[1]) + 1;
                                }
                            }
                            if (file_exists(DELAY_STREAM . $stream_id . '_.m3u8_old')) {
                                file_put_contents(DELAY_STREAM . $stream_id . '_.m3u8_old', file_get_contents(DELAY_STREAM . $stream_id . '_.m3u8_old') . file_get_contents(DELAY_STREAM . $stream_id . '_.m3u8'));
                                shell_exec('sed -i \'/EXTINF\\|.ts/!d\' ' . DELAY_STREAM . $stream_id . '_.m3u8_old');
                            } else {
                                copy(DELAY_STREAM . $stream_id . '_.m3u8', DELAY_STREAM . $stream_id . '_.m3u8_old');
                            }
                        }
                        $ffmpeg_cmd .= implode(' ', self::buildFFmpegArgs($stream['stream_info']['transcode_attributes'])) . ' ';
                        $ffmpeg_cmd .= '{MAP} -individual_header_trailer 0 -f segment -segment_format mpegts -segment_time ' . ipTV_lib::$SegmentsSettings['seg_time'] . ' -segment_list_size ' . $stream['stream_info']['delay_minutes'] * 6 . " -segment_start_number {$segment_start_number} -segment_format_options \"mpegts_flags=+initial_discontinuity:mpegts_copyts=1\" -segment_list_type m3u8 -segment_list_flags +live+delete -segment_list \"" . DELAY_STREAM . $stream_id . '_.m3u8" "' . DELAY_STREAM . $stream_id . '_%d.ts" ';
                        $delay_minutes = $stream['stream_info']['delay_minutes'] * 60;
                        if ($segment_start_number > 0) {
                            $delay_minutes -= ($segment_start_number - 1) * 10;
                            if ($delay_minutes <= 0) {
                                $delay_minutes = 0;
                            }
                        }
                    }
                    $ffmpeg_cmd .= ' >/dev/null 2>>' . STREAMS_PATH . $stream_id . '.errors & echo $! > ' . STREAMS_PATH . $stream_id . '_.pid';
                    $ffmpeg_cmd = str_replace(array('{INPUT}', '{FETCH_OPTIONS}', '{GEN_PTS}', '{STREAM_SOURCE}', '{MAP}', '{READ_NATIVE}', '{CONCAT}', '{AAC_FILTER}'), array("\"{$stream_source}\"", empty($stream['stream_info']['custom_ffmpeg']) ? $fetch_options : '', empty($stream['stream_info']['custom_ffmpeg']) ? $gen_pts : '', $stream_source, empty($stream['stream_info']['custom_ffmpeg']) ? $map : '', empty($stream['stream_info']['custom_ffmpeg']) ? $read_native : '', $stream['stream_info']['type_key'] == 'created_live' && $stream['server_info']['parent_id'] == 0 ? '-safe 0 -f concat' : '', !stristr($probe_json['container'], 'flv') && $probe_json['codecs']['audio']['codec_name'] == 'aac' && $stream['stream_info']['transcode_attributes']['-acodec'] == 'copy' ? '-bsf:a aac_adtstoasc' : ''), $ffmpeg_cmd);
                    shell_exec($ffmpeg_cmd);
                    $pid = $pid = intval(file_get_contents(STREAMS_PATH . $stream_id . '_.pid'));
                    if (SERVER_ID == $stream['stream_info']['tv_archive_server_id']) {
                        shell_exec(PHP_BIN . ' ' . TOOLS_PATH . 'archive.php ' . $stream_id . ' >/dev/null 2>/dev/null & echo $!');
                    }
                    $delay_enabled = $stream['stream_info']['delay_minutes'] > 0 && $stream['server_info']['parent_id'] == 0 ? true : false;
                    $delay_start = $delay_enabled ? time() + $delay_minutes : 0;
                    self::$ipTV_db->query('UPDATE `streams_sys` SET `delay_available_at` = \'%d\',`to_analyze` = 0,`stream_started` = \'%d\',`stream_info` = \'%s\',`stream_status` = 0,`pid` = \'%d\',`progress_info` = \'%s\',`current_source` = \'%s\' WHERE `stream_id` = \'%d\' AND `server_id` = \'%d\'', $delay_start, time(), json_encode($probe_json), $pid, json_encode(array()), $source, $stream_id, SERVER_ID);
                    $playlist = !$delay_enabled ? STREAMS_PATH . $stream_id . '_.m3u8' : DELAY_STREAM . $stream_id . '_.m3u8';
                    return array('main_pid' => $pid, 'stream_source' => $stream_source, 'delay_enabled' => $delay_enabled, 'parent_id' => $stream['server_info']['parent_id'], 'delay_start_at' => $delay_start, 'playlist' => $playlist);
                
                    
                } else {
                    $stream['stream_info']['transcode_attributes'] = array();
                    // Zmienna $d1006c7cc041221972025137b5112b7d w kodzie źródłowym pochodziła najpewniej z zewnętrznej konfiguracji jako undefined
                    // więc zmieniłem ją na $extra_ffmpeg_args z pustościa domyślną.
                    $extra_ffmpeg_args = ""; 
                    $ffmpeg_cmd = FFMPEG_PATH . " -y -nostdin -hide_banner -loglevel quiet {$extra_ffmpeg_args} -progress \"{$progress}\" " . $stream['stream_info']['custom_ffmpeg'];
                }
            }
        }
    }
    
    public static function customOrder($a, $b)
    {
        if (substr($a, 0, 3) == '-i ') {
            return -1;
        }
        return 1;
    }
    
    public static function buildStreamArguments($stream_arguments, $server_protocol, $type)
    {
        $parsed_args = array();
        if (!empty($stream_arguments)) {
            foreach ($stream_arguments as $arg_id => $arg_data) {
                if ($arg_data['argument_cat'] != $type) {
                    continue;
                }
                if (!is_null($arg_data['argument_wprotocol']) && !stristr($server_protocol, $arg_data['argument_wprotocol']) && !is_null($server_protocol)) {
                    continue;
                }
                if ($arg_data['argument_type'] == 'text') {
                    $parsed_args[] = sprintf($arg_data['argument_cmd'], $arg_data['value']);
                } else {
                    $parsed_args[] = $arg_data['argument_cmd'];
                }
            }
        }
        return $parsed_args;
    }
    
    public static function buildFFmpegArgs($attrs)
    {
        $filters = array();
        foreach ($attrs as $k => $arg_data) {
            if (isset($arg_data['cmd'])) {
                $attrs[$k] = $arg_data = $arg_data['cmd'];
            }
            if (preg_match('/-filter_complex "(.*?)"/', $arg_data, $matches)) {
                $attrs[$k] = trim(str_replace($matches[0], '', $attrs[$k]));
                $filters[] = $matches[1];
            }
        }
        if (!empty($filters)) {
            $attrs[] = '-filter_complex "' . implode(',', $filters) . '"';
        }
        $final_attrs = array();
        foreach ($attrs as $k => $attr_val) {
            if (is_numeric($k)) {
                $final_attrs[] = $attr_val;
            } else {
                $final_attrs[] = $k . ' ' . $attr_val;
            }
        }
        $final_attrs = array_filter($final_attrs);
        uasort($final_attrs, array(__CLASS__, 'customOrder'));
        return array_map('trim', array_values(array_filter($final_attrs)));
    }
    
    public static function ParseStreamURL($stream_url)
    {
        $server_protocol = strtolower(substr($stream_url, 0, 4));
        if (($server_protocol == 'rtmp')) {
            if (stristr($stream_url, '$OPT')) {
                $opt_rtmp = 'rtmp://$OPT:rtmp-raw=';
                $stream_url = trim(substr($stream_url, stripos($stream_url, $opt_rtmp) + strlen($opt_rtmp)));
            }
            $stream_url .= ' live=1 timeout=10';
        }
        else if ($server_protocol == 'http') {
            $youtube_dl_domains = array('youtube.com', 'youtu.be', 'livestream.com', 'ustream.tv', 'twitch.tv', 'vimeo.com', 'facebook.com', 'dailymotion.com', 'cnn.com', 'edition.cnn.com', 'youporn.com', 'pornhub.com', 'youjizz.com', 'xvideos.com', 'redtube.com', 'ruleporn.com', 'pornotube.com', 'skysports.com', 'screencast.com', 'xhamster.com', 'pornhd.com', 'pornktube.com', 'tube8.com', 'vporn.com', 'giniko.com', 'xtube.com');
            $host_domain = str_ireplace('www.', '', parse_url($stream_url, PHP_URL_HOST));
            if (in_array($host_domain, $youtube_dl_domains)) {
                $urls = trim(shell_exec(YOUTUBE_PATH . " \"{$stream_url}\" -q --get-url --skip-download -f best"));
                $stream_url = explode('', $urls)[0];
            }
        }
        return $stream_url;
    }
}
?>
