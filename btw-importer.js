jQuery(document).ready(function($){
    $('#startImport').click(function(){
        const fileInput = $('#atomFile')[0];
        if (!fileInput.files.length) {
            alert('Please select a .atom file first!');
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e){
            const atomContent = e.target.result;
            $('#progress').html('📦 Parsing...');

            $.post(btwImporter.ajaxUrl, {
                action: 'btw_prepare_import',
                nonce: btwImporter.nonce,
                atom_content: atomContent
            }, function(response){
                if (!response.success) {
                    $('#progress').append('<br>❌ '+response.data);
                    return;
                }

                const allItems = response.data.posts || [];
                if (!allItems.length) {
                    $('#progress').append('<br>⚠ No posts/pages found.');
                    return;
                }

                // split into posts & pages
                const posts = allItems.filter(item => item.post_type === 'post');
                const pages = allItems.filter(item => item.post_type === 'page');

                $('#progress').append('<br>✅ Found: '+posts.length+' posts and '+pages.length+' pages');

                // start with posts
                if (posts.length) {
                    importNext(0, posts, function(){
                        // after posts, import pages
                        if (pages.length) {
                            $('#progress').append('<br>📦 Now importing pages...');
                            importNext(0, pages, function(){
                                $('#progress').append('<br>🎉 All posts & pages imported!');
                            });
                        } else {
                            $('#progress').append('<br>🎉 All posts imported!');
                        }
                    });
                } else if (pages.length) {
                    $('#progress').append('<br>📦 Only pages to import...');
                    importNext(0, pages, function(){
                        $('#progress').append('<br>🎉 All pages imported!');
                    });
                } else {
                    $('#progress').append('<br>⚠ Nothing to import.');
                }
            });
        };
        reader.readAsText(fileInput.files[0]);
    });

    function importNext(index, items, doneCallback) {
        if (index >= items.length) {
            doneCallback();
            return;
        }

        const post = items[index];
        $('#progress').append('<br>📄 Importing '+escapeHtml(post.post_type)+': '+escapeHtml(post.title));
        scrollToBottom();

        $.post(btwImporter.ajaxUrl, {
            action: 'btw_import_single_post',
            nonce: btwImporter.nonce,
            post: post
        }, function(response){
            if (response.success && Array.isArray(response.data)) {
                response.data.forEach(msg => {
                    $('#progress').append('<br>'+escapeHtml(msg));
                });
            } else {
                $('#progress').append('<br>❌ Failed: '+escapeHtml(response.data));
            }
            scrollToBottom();
            importNext(index+1, items, doneCallback);
        });
    }

    function scrollToBottom() {
        const progress = $('#progress');
        progress.scrollTop(progress[0].scrollHeight);
    }

    function escapeHtml(text) {
        return $('<div>').text(text).html();
    }
});
