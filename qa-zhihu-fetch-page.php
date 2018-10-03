<?php

class qa_zhihu_fetch_page
{
	private $directory;
	private $urltoroot;


	public function load_module($directory, $urltoroot)
	{
		$this->directory = $directory;
		$this->urltoroot = $urltoroot;

		$this->install();
	}

	function install() {
		if (!$this->check_install()) {
			$this->do_install();
		}
	}
	function check_install() {
		$r = qa_db_query_sub('show tables like "^zhihu_fetch"');
		return qa_db_read_one_value($r, true);
	}
	function do_install() {
		qa_db_query_sub("
		CREATE TABLE `^zhihu_fetch` (
	`id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`created` DATETIME NOT NULL,
	`q_title` VARCHAR(255) NOT NULL COLLATE 'utf8_unicode_ci',
	`a_content` TEXT NOT NULL COLLATE 'utf8_unicode_ci',
	`uid` VARCHAR(155) NOT NULL COLLATE 'utf8_unicode_ci',
	`user_name` VARCHAR(155) NOT NULL COLLATE 'utf8_unicode_ci',
	PRIMARY KEY (`id`)
)
COLLATE='utf8_unicode_ci'
ENGINE=InnoDB
;
");
	}


	public function suggest_requests() // for display in admin interface
	{
		return array(
			array(
				'title' => 'Zhihu Fetch',
				'request' => 'zhihu-fetch-plugin-page',
				'nav' => 'M', // 'M'=main, 'F'=footer, 'B'=before main, 'O'=opposite main, null=none
			),
		);
	}


	public function match_request($request)
	{
		return $request == 'zhihu-fetch-plugin-page';
	}


	function insert_one($user_id, $user_name,$q_title,$answer_content) {
		qa_db_query_sub('INSERT INTO `^zhihu_fetch` (`created`, `q_title`, `a_content`, `uid`, `user_name`) VALUES
		(now(), $, $, $,$);',
		$q_title, $answer_content, $user_id, $user_name);
		list($qid, $aid) =$this->insert_q2a($user_id, $user_name,$q_title,$answer_content);
		return [$qid, $q_title,$aid];
	}
	function insert_q2a($user_id, $user_name,$q_title,$answer_content) {
		$userid = $this->insert_q2a_user($user_id, $user_name);
		$qid=$this->insert_q2a_question($q_title, $userid);
		$aid= $this->insert_q2a_answer($qid, $userid,$answer_content);
		return [$qid,$aid];
	}
	function insert_q2a_user($user_id, $user_name)
	{
		$sql = 'SELECT userid from ^users where handle=$ limit 1';
		$userid = qa_db_read_one_value(qa_db_query_sub($sql, $user_name), true);
		if (!$userid) {
			qa_db_query_sub('INSERT INTO `^users` 
			(`created`, `createip`, `email`, `handle`, `level`, `loggedin`, `loginip`) VALUES 
			(now(), 0x00, $, $, "0", now(), 0x000000)',
			$user_id."@zhihu", $user_name);
			$userid = qa_db_connection()->insert_id;
			qa_db_query_sub('INSERT INTO `^userpoints` (`userid`) VALUES (#)',
			$userid);
			// qa_db_query_sub('INSERT INTO `^userprofile` (`userid`, title,content) VALUES (#,"name",$)',
			// $userid,$user_name);
		}
		return $userid;
	}
	function insert_q2a_question($q_title, $userid) {
		$sql = 'SELECT postid from ^posts where type="Q" and title=$ limit 1';
		$postid=qa_db_read_one_value(qa_db_query_sub($sql, $q_title), true);
		if (!$postid) {
			$type = 'Q';
			$postid=qa_post_create($type, $parentpostid=null, $title=$q_title, $content='', $format='html', $categoryid=null, $tags='', $userid);
		} 
		return $postid;
	}
	function insert_q2a_answer($qid, $userid,$answer_content) {
		$sql = 'SELECT postid from ^posts where type="A" and parentid=# and userid=# limit 1';
		$postid=qa_db_read_one_value(qa_db_query_sub($sql, $qid,$userid), true);
		if (!$postid) {
			$postid=qa_post_create($type='A', $parentpostid=$qid, $title='', $content=$answer_content, $format='html', $categoryid=null, $tags='', $userid);
		} 
		return $postid;
	}
	function fetch($url) {
		if (
			0&&
			is_file(__DIR__.'/cache')) {
			$html = file_get_contents(__DIR__.'/cache');
		} else {
			$html = file_get_contents($url);
		}
		if (!$html) {
			$qa_content['error'] = '抓取内容为空';
		} else {
			// file_put_contents(__DIR__.'/cache', $html);

			$doc = phpQuery::newDocument($html);
			$answer_content = $doc['.AnswerItem .RichContent-inner .RichText']->html();
			$u = $doc['.UserLink-link'];
			$href= $u->attr('href');
			if (preg_match('#/([^/]+)$#', $href, $m)) {
				$user_id = $m[1];
			}
			$user_name= trim($u->text());
			$q_title = trim($doc['.QuestionHeader-title']->text());
			list($qid, $title, $aid) = $this->insert_one($user_id, $user_name,$q_title,$answer_content);
			return [qa_q_path($qid, $title, true, 'A', $aid),$title];
		}
	}
	public function process_request($request)
	{
		require_once __DIR__.'/phpQuery/phpQuery/phpQuery.php';
		require_once QA_INCLUDE_DIR . 'app/posts.php';
		require_once QA_INCLUDE_DIR . 'app/post-create.php';

		// ini_set('error_log', __DIR__.'/php.log');

		$url = isset($_POST['url']) ? trim($_POST['url']) : '';

		$qa_content = qa_content_prepare();
		$qa_content['title'] = qa_lang_html('zhihu_fetch_page/page_title');
		$qa_content['error'] = '';
		$qa_content['custom'] = '输入答案地址，抓取答案（确定要经过作者同意）';

		$url_error = '';
		$link = '';
		if (!qa_get_logged_in_userid())
			$qa_content['error'] = '必须登录才能使用';
		elseif (qa_get_logged_in_level() <QA_USER_LEVEL_ADMIN)
			$qa_content['error'] = '必须是管理员才能使用';
		elseif ($url) {
			$a = parse_url($url);

			if ($a['host']!='www.zhihu.com') {
				$url_error = '只能抓取知乎';
			} elseif (!preg_match('#^/question/\d+/answer/\d+$#', $a['path'])) {
				$url_error = '只能抓取答案';				
			} else {
				list($link,$title) = $this->fetch($url);
			}
		}

		$qa_content['form'] = array(
			'tags' => 'method="post" action="' . qa_self_html() . '"',

			'style' => 'wide',

			'ok' => $link ? '答案抓取完毕：<a target="_blank" href="'.qa_html($link).'">'.qa_html($title)."</a>" : null,

			'title' => '抓取',

			'fields' => array(
				'url' => array(
					'label' => '知乎答案地址',
					'tags' => 'name="url"',
					'value' => qa_html($url),
					'error' => qa_html($url_error),
				),

			),

			'buttons' => array(
				'ok' => array(
					'tags' => 'name="okthen"',
					'label' => '抓取',
					'value' => '1',
				),
			),

			'hidden' => array(
				'hiddenfield' => '1',
			),
		);

		$qa_content['custom_2'] = '';

		return $qa_content;
	}
}
