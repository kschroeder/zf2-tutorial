<?php

namespace Album\Controller;

use Zend\I18n\Filter\Alnum;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Album\Model\Album;
use Album\Form\AlbumForm;

class AlbumController extends AbstractActionController
{
    protected $albumTable;

    public function indexAction()
    {
    	$albumData = $this->getAlbumTable()->fetchAll();
    	$albumMeta = array();
    	$albums = array();
    	foreach ($albumData as $album) {
    		$albums[] = $album;
    		$params = array(
    			'entity'		=> 'musicTrack',
    			'term'			=> $album->title
    		);
    		
    		$url = 'http://itunes.apple.com/search?' . http_build_query($params);
    		$results = file_get_contents($url);
    		$filter = new Alnum();
    		if ($results && ($results = json_decode($results, true)) != false) {
    			$users = array();
    			foreach ($results['results'] as $result) {
    				if ($filter->filter($result['artistName']) == $filter->filter($album->artist)) {
    					if (!isset($albumMeta[$album->id])) {
    						$albumMeta[$album->id] = array(
    							'image'		=> $result['artworkUrl60'],
    							'genre'		=> $result['primaryGenreName'],
    							'tracks'	=> array()		
    						);
    					}
    					$config = $this->getServiceLocator()->get('config');
    					
    					$sResults = file_get_contents($config['soundcloud']['baseUrl'] . '/tracks.json?q=' . urlencode($result['trackName']) . '&client_id=' . $config['soundcloud']['key']);
    					error_log($config['soundcloud']['baseUrl'] . '/tracks.json?q=' . urlencode($result['trackName']));
    					$sResults = json_decode($sResults, true);
    					foreach ($sResults as $sResult) {
	    					$user = $sResult['user'];
	    					
	    					if (isset($users[$user['id']])) {
	    						$userResults = $users[$user['id']];
	    					} else {
		    					$userResults = file_get_contents($config['soundcloud']['baseUrl'] . '/users/' . $user['id'] . '.json?client_id=' . $config['soundcloud']['key']);
		    					error_log($config['soundcloud']['baseUrl'] . '/users/' . $user['id'] . '.json');
		    					$userResults = json_decode($userResults, true);
		    					$users[$user['id']] = $userResults;
	    					}
	    					if ($userResults['full_name'] == $album->artist) {
		    					$albumMeta[$album->id]['tracks'][] = array(
		    							'title'			=> $result['trackName'],
		    							'soundcloud'	=> $sResult
		    					);
		    					break;
	    					}
    					}
	    			}
    			}
    		}
    	}
    	
        return new ViewModel(array(
            'albums' 	=> $albums,
        	'albumMeta'	=> $albumMeta
        ));
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
                $this->getAlbumTable()->saveAlbum($album);

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
