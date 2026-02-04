function embed_pdf_from_acf() {
    $pdf_url = get_field('upload_resume'); // Replace 'upload_resume' with your field name
    if ($pdf_url) {
        return '<iframe src="https://docs.google.com/gview?url=' . esc_url($pdf_url) . '&embedded=true" style="width:100%; height:1000px;" frameborder="0"></iframe>';
    }
    return 'No PDF available.';
}
add_shortcode('acf_pdf_embed', 'embed_pdf_from_acf');
