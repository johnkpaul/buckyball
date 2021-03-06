<?php
/**
* Copyright 2011 Unirgy LLC
*
* Licensed under the Apache License, Version 2.0 (the "License");
* you may not use this file except in compliance with the License.
* You may obtain a copy of the License at
*
* http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.
*/

class Blog
{
    static public function init()
    {
        BFrontController::i()
        // public access
            ->route('GET /', 'Blog_Public.index')
            ->route('GET /posts/:post_id', 'Blog_Public.post')
            ->route('POST /posts/:post_id/comments/', 'Blog_Public.new_comment')

        // admin access
            ->route('POST /login', 'Blog_Admin.login')
            ->route('POST /posts/', 'Blog_Admin.new_post')
            ->route('POST /posts/:post_id', 'Blog_Admin.update_post')
            ->route('POST /posts/:post_id/comments/:com_id', 'Blog_Admin.update_comment')
            ->route('GET /comments/', 'Blog_Admin.comments')
            ->route('GET /logout', 'Blog_Admin.logout')
        ;

        BLayout::i()
            ->rootView('main')
            ->allViews('protected/view')
            ->view('head', array('view_class'=>'BViewHead'))
        ;

        BPubSub::i()->on('BLayout::render.before', 'Blog::layout_render_before');

        BDb::migrate('Blog::migrate');
    }

    public static function user()
    {
        return BSession::i()->data('user');
    }

    public static function redirect($url, $status, $msg, $msgArgs=array())
    {
        $url = BApp::url('Blog', $url).'?status='.$status.'&msg='.urlencode(BApp::t($msg, $msgArgs));
        BResponse::i()->redirect($url);
    }

    public static function q($str)
    {
        return strip_tags($str, '<a><p><b><i><u><ul><ol><li><strong><em><br><img>');
    }

    public static function layout_render_before($args)
    {
        $layout = BLayout::i();
        $layout->view('head')->css('css/common.css', array());

        $request = BRequest::i();
        if ($request->get('status') && $request->get('msg')) {
            $layout->view('main')->set(array(
                'messageClass' => $request->get('status'),
                'message' => $request->get('msg'),
            ));
        }
    }

    public static function migrate()
    {
        BDb::install('0.1.0', "

CREATE TABLE IF NOT EXISTS `".BlogPost::table()."` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` text,
  `preview` text,
  `body` text,
  `posted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `posted_at` (`posted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `".BlogPostComment::table()."` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `body` text,
  `posted_at` datetime DEFAULT NULL,
  `approved` tinyint(4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`,`approved`,`posted_at`),
  CONSTRAINT `FK_".BlogPostComment::table()."_post` FOREIGN KEY (`post_id`) REFERENCES `".BlogPost::table()."` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

        ");
    }
}

class BlogPost extends BModel {}

class BlogPostComment extends BModel {}

class Blog_Public extends BActionController
{
    public function action_index()
    {
        $layout = BLayout::i();
        $layout->hookView('body', 'index');
        $layout->view('index')->posts = BlogPost::factory()->table_alias('b')
            ->select('id')->select('title')->select('preview')->select('posted_at')
            ->select_expr('(select count(*) from '.BlogPostComment::table().' where post_id=b.id and approved)', 'comment_count')
            ->order_by_desc('posted_at')
            ->find_many();

        BResponse::i()->output();
    }

    public function action_post()
    {
        $postId = BRequest::i()->params('post_id');
        $post = BlogPost::load($postId);
        if (!$post) {
            Blog::redirect('/', 'error', "Post not found!");
            #$this->forward('noroute');
        }
        $commentsORM = BlogPostComment::factory()
            ->select('id')->select('name')->select('body')->select('posted_at')->select('approved')
            ->where('post_id', $postId)
            ->order_by_asc('posted_at');
        if (!Blog::user()) {
            $commentsORM->where('approved', 1);
        }
        $comments = $commentsORM->find_many();

        $layout = BLayout::i();
        $layout->hookView('body', 'post');
        $layout->view('post')->set(array('post'=>$post, 'comments'=>$comments));

        BResponse::i()->output();
    }

    public function action_new_comment()
    {
        $request = BRequest::i();
        try {
            $post = BlogPost::load($request->params('post_id'));
            if (!$post) {
                throw new Exception("Invalid post");
            }
            if (!$request->post('name') || !$request->post('body')) {
                throw new Exception("Not enough information for comment!");
            }
            $comment = BlogPostComment::create(array(
                'post_id'   => $post->id,
                'name'      => $request->post('name'),
                'body'      => $request->post('body'),
                'posted_at' => BDb::now(),
                'approved'  => Blog::user() ? 1 : 0,
            ));
            $comment->save();

            $msg = "Thank you for your comment!".(!Blog::user() ? " It will appear after approval." : "");
            Blog::redirect('/posts/'.$post->id, 'success',  $msg);
        } catch (Exception $e) {
            Blog::redirect(empty($post) ? '/' : '/posts/'.$post->id, 'error', $e->getMessage());
        }
    }

    public function action_noroute()
    {
        BLayout::i()->hookView('body', '404');
        BResponse::i()->status(404);
    }
}

class Blog_Admin extends BActionController
{
    public function authorize($args=array())
    {
        return $this->_action=='login' || Blog::user()=='admin';
    }

    public function action_login()
    {
        $request = BRequest::i();
        try {
            if (!($request->post('username')=='admin' && $request->post('password')=='admin')) {
                throw new Exception("Invalid user name or password");
            }
            BSession::i()->data('user', 'admin');
            Blog::redirect('/', 'success',  "You're logged in as admin");
        } catch (Exception $e) {
            Blog::redirect('/', 'error', $e->getMessage());
        }
    }

    public function action_logout()
    {
        BSession::i()->data('user', false);
        Blog::redirect('/', 'success', "You've been logged out");
    }

    public function action_new_post()
    {
        $request = BRequest::i();
        try {
            if (!$request->post('title') || !$request->post('body')) {
                throw new Exception("Invalid post data");
            }

            $post = BlogPost::create(array(
                'title' => $request->post('title'),
                'preview' => $request->post('preview'),
                'body' => $request->post('body'),
                'posted_at' => BDb::now(),
            ));
            $post->save();

            Blog::redirect('/posts/'.$post->id, 'success',  "New post has been created!");
        } catch (Exception $e) {
            Blog::redirect('/', 'error', $e->getMessage());
        }
    }

    public function action_update_post()
    {
        $request = BRequest::i();
        try {
            if (!$request->post('title') || !$request->post('body')) {
                throw new Exception("Invalid post data");
            }

            $post = BlogPost::load($request->params('post_id'));
            if (!$post) {
                throw new Exception("Invalid post ID");
            }
            if ($request->post('action')=='Delete') {
                $post->delete();
                Blog::redirect('/', 'success',  "The post has been deleted!");
            } else {
                $post->title = $request->post('title');
                $post->preview = $request->post('preview');
                $post->body = $request->post('body');
                $post->save();
                Blog::redirect('/posts/'.$post->id, 'success',  "The post has been updated!");
            }
        } catch (Exception $e) {
            Blog::redirect('/', 'error', $e->getMessage());
        }
    }

    public function action_update_comment()
    {
        $request = BRequest::i();
        try {
            $post = BlogPost::load($request->params('post_id'));
            if (!$post) {
                throw new Exception("Invalid post ID");
            }
            $comment = BlogPostComment::load($request->params('com_id'));
            if (!$comment || $comment->post_id != $post->id) {
                throw new Exception("Invalid comment ID");
            }
            if ($request->post('action')=='Delete') {
                $comment->delete();
                Blog::redirect('/posts/'.$post->id, 'success',  "The comment has been deleted!");
            } else {
                $comment->approved = $request->post('approved');
                $comment->save();
                Blog::redirect('/posts/'.$post->id, 'success',  "The comment has been updated!");
            }
        } catch (Exception $e) {
            Blog::redirect('/', 'error', $e->getMessage());
        }
    }
}