<?php

/**
 * The Post Thumbnail Editor API
 *
 * @link       http://sewpafly.github.io/post-thumbnail-editor
 * @since      3.0.0
 *
 * @package    Post_Thumbnail_Editor
 * @subpackage Post_Thumbnail_Editor/includes
 */

class PTE_Api extends PTE_Hooker{

	/**
	 * The cached thumbnails
	 *
	 * @since    3.0.0
	 * @access   private
	 * @var      array    $thumbnails    The wordpress thumbnail information
	 */
	private $thumbnails;

	/**
	 * Constructor for PTE_Api sets the arg_keys needed for ahooks (See
	 * PTE_Hooker)
	 *
	 * @since 3.0.0
	 *
	 * @param mixed 
	 */
	public function __construct () {

		$this->arg_keys['derive_dimensions'] = array( 'size', 'w', 'h' );
		$this->arg_keys['derive_transparency'] = array(
			'w',
			'h',
			'dst_w',
			'dst_h'
	   	);
		$this->arg_keys['derive_paths'] = array(
			'id',
			'original_file',
			'dst_w',
			'dst_h',
			'transparent',
			'save'
	   	);

	}
	

	/**
	 * Assert that the current user has access to the given image/post
	 *
	 * @since 3.0.0
	 * @param int    $id    The Post ID of the image you want to check
	 * @return true if valid
	 */
	public function assert_valid_id ( $id ) {

		if ( !$post = get_post( $id ) ) {
			return false;
		}
		$user_can = current_user_can( 'edit_post', $id )
			|| current_user_can( 'pte-edit', $id );

		/**
		 * Check for user capability.
		 *
		 * Return if the user should be able to view the id
		 *
		 * @since 2.0.0
		 * @param int      $id      The post id
		 */
		$user_can = apply_filters( 'pte-capability-check', $user_can, $id );

		if ( $user_can ) return true;

		return false;

	}

	/**
	 * Hook to retrieve the size information from wordpress
	 *
	 * @since 3.0.0
	 *
	 * @param mixed    $filter  if present, (not 'none') apply the filter above.
	 *                          A 'truthy' value will check the options for
	 *                          filtered values.  An array will only return
	 *                          sizes with names in the array, and a single name
	 *                          will return just that size. 
	 *
	 * @return mixed   $sizes   The list of Thumbnail objects
	 */
	public function get_sizes ( $filter = null ) {

		if ( ! isset( $this->thumbnails ) ) {
			$this->thumbnails = PTE_Thumbnail_Size::get_all();
		}

		if ( empty( $filter ) ) {
			return $this->thumbnails;
		}

		$filtered_sizes = apply_filters( 'pte_options_get', array(), 'pte_hidden_sizes');

		foreach ( $this->thumbnails as $size ) {
			if ( in_array( $size->name, $filtered_sizes ) ) {
				continue;
			}
			if ( is_array( $filter ) && in_array( $size->name, $filter ) || $filter == $size->name ) {
				$thumbnails[] = $size;
			}
		}
		return $thumbnails;

	}
	
	/**
	 * Resize Images
	 *
	 * Given the appropriate parameters resize the given images, and place in a
	 * temporary directory (or save in place with the right option)
	 *
	 * @since 0.1
	 * 
	 * @param int     $id    The post/attachment id to modify
	 * @param int     $w     The width of the crop
	 * @param int     $h     The height of the crop
	 * @param int     $x     The left position of the crop
	 * @param int     $y     The upper position of the crop
	 * @param array   $sizes An array of the thumbnails to modify
	 * @param string  $save  Save without confirmation
	 *
	 * @return array of PTE_ActualThumbnail, and any errors
	 */
	public function resize_thumbnails ( $id, $w, $h, $x, $y, $sizes, $save=false ) {

		$data = compact( 'id', 'w', 'h', 'x', 'y', 'save' );
		$data['original_file'] = _load_image_to_edit_path( $id );
		$data['original_size'] = @getimagesize( $data['original_file'] );

		if ( ! isset( $data['original_size'] ) ) {
			throw new Exception( __( 'Could not read image size', 'post-thumbnail-editor' ) );
		}

		$thumbnails = array();
		$errors = array();

		foreach ( $this->get_sizes( $sizes ) as $size ) {
			$data['size'] = $size;
			try {
				
				$thumbnail = $this->resize_thumbnail( $data );
				if ( ! empty( $thumbnail ) ) {
					$thumbnails[] = $thumbnail;
				}

			} 
			catch (Exception $e) {
				$errors[$size->name] = $e->getMessage();
			}
		}

		return compact( 'thumbnails', 'errors' );
	}

	/**
	 * Load our extended GD and ImageMagick Editocs
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function load_pte_editors () {

		add_filter( 'wp_image_editors', array( $this, 'image_editors' ) );

	}

	/**
	 * Return our image editors
	 *
	 * @since 3.0.0
	 *
	 * @param array {
	 *     @var WP_Image_Editor class name
	 * }
	 *
	 * @return array {
	 *     @var WP_Image_Editor class name
	 * }
	 */
	public function image_editors ( $editors ) {

		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-pte-image-editor-gd.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-pte-image-editor-imagick.php';

		array_unshift( $editors, 'PTE_Image_Editor_Imagick', 'PTE_Image_Editor_GD' );

		return $editors;

	}
	
	
	/**
	 * Resize an individual thumbnail
	 *
	 * @since 3.0.0
	 *
	 * @param mixed $params {
	 *	   @type int                 $id       The post id to resize
	 *	   @type PTE_Thumbnail_Size  $size     The thumbnail size
	 *	   @type int                 $w        The proposed width
	 *	   @type int                 $h        The proposed height
	 *	   @type int                 $x        The proposed starting left point
	 *	   @type int                 $y        The proposed starting upper
	 *	                                       point
	 *	   @type int/boolean         $save     Should the image be saved
	 *	   @type string              $tmp_dir  Temporary directory
	 *	   @type string              $tmp_file Temporary directory
	 *	   @type array          $original_size The original file image
	 *	                                       parameters
	 *	   @type string         $original_file The original file name
	 * }
	 *
	 * @return PTE_Thumbnail
	 */
	private function resize_thumbnail ( $params ) {

		/**
		 * Action `pte_resize_thumbnail' is triggered when resize_thumbnails is
		 * ready to roll (after the parameters and correctly compiled and just
		 * before the resize_thumbnail function is called.
		 *
		 * @since 3.0.0
		 *
		 * @param See above
		 *
		 * @return filtered params ready to modify the image
		 */
		$params = apply_filters( 'pte_api_resize_thumbnail', $params );

		$editor = wp_get_image_editor( $params['original_file'] );

		if ( is_wp_error( $editor ) ) {
			throw new Exception( sprintf(
				__( 'Unable to load file: %s', 'post-thumbnail-editor' ),
				$params['original_file']
			) );
		}

		$crop_results = $editor->crop(
			$params['x'],
			$params['y'],
			$params['w'],
			$params['h'],
			$params['dst_w'],
			$params['dst_h']
		);

		if ( is_wp_error( $crop_results ) ) {
			throw new Exception( sprintf(
				__( 'Error cropping image: %s', 'post-thumbnail-editor' ),
				$params['size']->name
			) );
		}

		wp_mkdir_p( dirname( $params['tmpfile'] ) );

		if ( is_wp_error( $editor->save( $params['tmpfile'] ) ) ) {
			throw new Exception( sprintf(
				__( 'Error writing image: %s to %s', 'post-thumbnail-editor' ),
				$params['size']->name,
				$params['tmpfile']
			) );
		}

		$thumbnail = new PTE_Thumbnail( $params['id'], $params['size'] );
		$oldfile = dirname( $params['original_file'] )
			. DIRECTORY_SEPARATOR
		   	. $thumbnail->file;
		$thumbnail->url = $params['tmpurl'];
		$thumbnail->file = $params['basename'];
		$thumbnail->width = $params['dst_w'];
		$thumbnail->height = $params['dst_h'];

		if ( $params['save'] ) {
			$thumbnail->save();
			$oldfile = apply_filters( 'pte_delete_file', $oldfile );
			@unlink( apply_filters( 'wp_delete_file', $oldfile ) );
		}

		return $thumbnail;

	}

	/**
	 * Are we adding borders?
	 *
	 * @since 2.0.0
	 *
	 * @param int $src_w
	 * @param int $src_h
	 * @param int $dst_w
	 * @param int $dst_h
	 *
	 * @return boolean borders?
	 */
	public function is_crop_border_enabled ( $src_w, $src_h, $dst_w, $dst_h ) {

		$src_ar = $src_w / $src_h;
		$dst_ar = $dst_w / $dst_h;
		return ( isset( $_REQUEST['pte-fit-crop-color'] ) && abs( $src_ar - $dst_ar ) > 0.01 );

	}
	
	/**
	 * Is the border transparent
	 *
	 * @since 2.0.0
	 *
	 * @return boolean transparent?
	 */
	public function is_crop_border_opaque () {

		if ( ! isset ( $_REQUEST['pte-fit-crop-color'] ) )
			return false;

		return ( preg_match( "/^#[a-fA-F0-9]{6}$/", $_REQUEST['pte-fit-crop-color'] ) );

	}
	
	/**
	 * ================================================================
	 *  Resize Thumbnail hooks - hooked into `pte_api_resize_thumbnail`
	 * ================================================================
	 */

	/**
	 * Derive the correct width/height given the input width/height and the
	 * output size
	 *
	 * @since 3.0.0
	 *
	 * @param PTE_Thumbnail_Size $size   The thumbnail size to output
	 * @param int                $width  The user selected width
	 * @param int                $height The user selected height
	 *
	 * @return array {
	 *    @type int width
	 *    @type int height
	 * }
	 */
	public function derive_dimensions ( $size, $w, $h ) {

		if ( $size->crop ) {

			$dst_w = $size->width;
			$dst_h = $size->height;

		}

		// Crop isn't set so the height / width should be based on the largest
		// side of the image itself, unless that side is set to 0 or something
		// really big, in which case use the other side.
		else {

			$use_width = false;

			if ( $w > $h ) $use_width = true;

			if ( ! $use_width && ( $size->height == 0 || $size->height > 9998 ) ) $use_width = true;
			else if ( $use_width && ( $size->width == 0 || $size->width > 9998 ) ) $use_width = false;

			if ( $use_width ) {

				$dst_w = $size->width;
				$dst_h = intval( round( ($dst_w / $w) * $h, 0 ) );

			}
			else {

				$dst_h = $size->height;
				$dst_w = intval( round( ($dst_h / $h) * $w, 0 ) );

			}

		}

		// Sanity Check
		if ( $dst_h == 0 || $dst_w == 0 ) {

			throw new Exception(
				sprintf(
					__( 'Invalid derived dimensions: %s x %s', 'post-thumbnail-editor' ),
					$dst_w, $dst_h
				)
		   	);

		}

		return compact( 'dst_w', 'dst_h' );

	}
	
	/**
	 * Set 'transparency' value
	 *
	 * @since 3.0.0
	 *
	 * @param int  $width
	 * @param int  $height
	 * @param int  $dst_w
	 * @param int  $dst_h
	 *
	 * @return array {
	 *     @var boolean $transparent  Does the image require transparency?
	 * }
	 */
	public function derive_transparency ( $src_w, $src_h, $dst_w, $dst_h ) {

		$crop_enabled = $this->is_crop_border_enabled( $src_w, $src_h, $dst_w, $dst_h );

		$opaque = $this->is_crop_border_opaque();

		return array( 'transparent' => $crop_enabled && $opaque ); 

	}
	
	/**
	 * Generate the paths for a thumbnail
	 *
	 * @since 2.0.0
	 *
	 * @param string  $file   The original file name
	 * @param int     $width  The thumbnail width
	 * @param int     $height The thumbnail height
	 * @param bool    $trans  If the image needs transparency it must have a
	 *                        .png extension
	 *
	 * @return array {
	 *    @type string  $tmpfile  The file to save
	 *    @type string  $tmpurl   The url of the file to save
	 * }
	 */
	public function derive_paths ( $id, $file, $w, $h, $transparent, $save=false) {

		$tmp_dir = apply_filters( 'pte_options_get', null, 'tmp_dir' );
		$tmp_url = apply_filters( 'pte_options_get', null, 'tmp_url' );

		$info         = pathinfo( $file );
		$ext          = (false !== $transparent) ? 'png' : $info['extension'];
		$name         = wp_basename( $file, ".$ext" );
		$suffix       = "{$w}x{$h}";

		if ( apply_filters( 'pte_options_get', false, 'cache_buster' ) ){
			$cache_buster = time();
			$basename = sprintf( 
				"%s-%s-%s.%s",
				$name,
				$suffix,
				$cache_buster,
				$ext 
			);
		}
		else {
			$basename = "{$name}-{$suffix}.{$ext}";
		}

		$basename = apply_filters( 'pte_api_resize_thumbnail_basename', $basename );

		if ( $save ) {
			$directory = dirname( $file ) . DIRECTORY_SEPARATOR;
			$tmp_url = dirname( wp_get_attachment_url( $id ) ) . "/";
		}
		else {
			$directory = $tmp_dir;
		}
		$directory = apply_filters( 'pte_api_resize_thumbnail_directory', $directory );

		$tmpfile = $directory . $basename;
		$tmpfile = apply_filters( 'pte_api_resize_thumbnail_file', $tmpfile, func_get_args() );

		$tmpurl = "{$tmp_url}{$basename}";
		$tmpurl = apply_filters( 'pte_api_resize_thumbnail_url', $tmpurl, func_get_args() );

		return compact( 'basename', 'tmpfile', 'tmpurl' );

	}
	
}
