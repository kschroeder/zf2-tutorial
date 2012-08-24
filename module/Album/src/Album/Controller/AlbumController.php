<?php

namespace Album\Controller;

use Zend\I18n\Filter\Alnum;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Album\Model\Album;
use Album\Form\AlbumForm;

class AlbumController extends AbstractActionController {
	protected $albumTable;
	public function indexAction() {
		$albumData = $this->getAlbumTable()->fetchAll();
		
		$albums = array();
		
		foreach ( $albumData as $album ) {
			$albums[] = $album->id;
		}
		
		return new ViewModel(array(
			'albums' 	=> $albums
		));
    }
    
    public function albuminfoAction()
    {
    	$albumId = $this->getRequest()->getQuery('album');
    	$album = $this->getAlbumTable()->getAlbum($albumId);
    	$meta = zend_disk_cache_fetch('album-meta-' . $albumId);
    	
    	$model =new ViewModel(
    		array(
    			'album'		=> $album,
    			'albumMeta'	=> $meta
    		)
    	);
    	
    	$model->setTerminal(true);
    	return $model;
    }
    
    public function processalbumAction()
    {
    	
    	$params = \ZendJobQueue::getCurrentJobParams();
    	if (!$params) return $this->getResponse();
    	$albumId = $params['albumId'];
    	
    	//$albumId = $this->getRequest()->getQuery('albumId');
    	$album = $this->getAlbumTable()->getAlbum($albumId);
    	if (!$album) return $this->getResponse();
    	$params = array (
    		'entity' 	=> 'musicTrack',
    		'term' 		=> $album->title
    	);
    		
    	$url = 'http://itunes.apple.com/search?' . http_build_query($params);
    	$results = file_get_contents($url);
    	$filter = new Alnum ();
    	$albumMeta = array();
    	if ($results && ($results = json_decode($results, true)) != false) {
    		$users = array();
    		foreach ($results['results'] as $result) {
    			if ($filter->filter ($result['artistName']) == $filter->filter($album->artist)
    					&& $filter->filter($result['collectionName']) == $filter->filter($album->title)) {
    				if (!isset($albumMeta['image'])) {
    					$albumMeta = array(
    						'image' 	=> $result['artworkUrl60'],
    						'genre' 	=> $result['primaryGenreName'],
    						'tracks' 	=> array()
    					);
    				}
    	
    				$albumMeta['tracks'][$result['trackName']] = array (
    					'title' 		=> $result ['trackName'],
    					'soundcloud' 	=> array ()
    				);
    	
    				$config = $this->getServiceLocator()->get('config');
    				
    				$sResults = file_get_contents(
    						'http://api.soundcloud.com/tracks.json?q='
    				 		. urlencode($result['trackName'])
    				 		. '&client_id='
    				 		. $config['soundcloud']['key']
    				);
    				error_log('http://api.soundcloud.com/tracks.json?q=' . urlencode($result['trackName']));
    				$sResults = json_decode($sResults, true);
    				$sResults = array_slice($sResults, 0, 3);
    				foreach ($sResults as $sResult) {
    					if (strpos ($sResult['title'], $result['trackName']) !== false) {
    						$albumMeta['tracks'][$result['trackName']]['soundcloud'][] = $sResult;
    					}
    				}
    			}
    		}
   		}
    	zend_disk_cache_store('album-meta-' . $albumId, $albumMeta);
    	return $this->getResponse();
    }

    public function addAction()
    {
        $form = new AlbumForm();
        $form->get('submit')->setAttribute('value', 'Add');

        $request = $this->getRequest();
        if ($request->isPost()) {
            $album = new Album();
            $form->setInputFilter($album->getInputFilter());
            $form->setData($request->getPost());
            if ($form->isValid()) {
                $album->exchangeArray($form->getData());
                $id = $this->getAlbumTable()->saveAlbum($album);
				$jq = new \ZendJobQueue();
				$jq->createHttpJob(
					'http://' . $_SERVER['HTTP_HOST'] . '/album/processalbum',
					array(
						'albumId'	=> $id
					)
				);
                // Redirect to list of albums
                return $this->redirect()->toRoute('album');
            }
        }

        return array('form' => $form);
    }

    public function editAction()
    {
        $id = (int)$this->params('id');
        if (!$id) {
            return $this->redirect()->toRoute('album', array('action'=>'add'));
        }
        $album = $this->getAlbumTable()->getAlbum($id);

        $form = new AlbumForm();
        $form->bind($album);
        $form->get('submit')->setAttribute('value', 'Edit');
        
        $request = $this->getRequest();
        if ($request->isPost()) {
            $form->setData($request->getPost());
            if ($form->isValid()) {
                $this->getAlbumTable()->saveAlbum($album);

                // Redirect to list of albums
                return $this->redirect()->toRoute('album');
            }
        }

        return array(
            'id' => $id,
            'form' => $form,
        );
    }

    public function deleteAction()
    {
        $id = (int)$this->params('id');
        if (!$id) {
            return $this->redirect()->toRoute('album');
        }

        $request = $this->getRequest();
        if ($request->isPost()) {
            $del = $request->getPost()->get('del', 'No');
            if ($del == 'Yes') {
                $id = (int)$request->getPost()->get('id');
                $this->getAlbumTable()->deleteAlbum($id);
                zend_disk_cache_delete('album-meta-' . $id);
            }

            // Redirect to list of albums
            return $this->redirect()->toRoute('album');
        }

        return array(
            'id' => $id,
            'album' => $this->getAlbumTable()->getAlbum($id)
        );
    }

    public function getAlbumTable()
    {
        if (!$this->albumTable) {
            $sm = $this->getServiceLocator();
            $this->albumTable = $sm->get('Album\Model\AlbumTable');
        }
        return $this->albumTable;
    }    
}
