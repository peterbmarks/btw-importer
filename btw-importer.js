jQuery(document).ready(function($) {
    let isImporting = false;
    
    // Enable button after checking the notice
    $('#agreeNotice').on('change', function() {
        if ($(this).is(':checked')) {
            $('#startImport').prop('disabled', false);
            $('#importNotice').slideUp(); // collapse nicely
        } else {
            $('#startImport').prop('disabled', true);
            $('#importNotice').slideDown(); // show again if unchecked
        }
    });

    $('#startImport').click(function() {
        const fileInput = $('#atomFile')[0];
        if (!fileInput.files.length) {
            alert('Please select a .atom file first!');
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            const atomContent = e.target.result;
            $('#progress').html('📦 Parsing... Please wait... Do not reload or leave this page.');

            isImporting = true; // start importing
            $('#importOverlay').show();
            $.post(btwImporter.ajaxUrl, {
                action: 'btw_prepare_import',
                nonce: btwImporter.nonce,
                atom_content: atomContent
            }, function(response) {
                if (!response.success) {
                    $('#progress').append('<br>❌ ' + escapeHtml(response.data));
                    isImporting = false; // stop on error
                    return;
                }

                const allItems = response.data.posts || [];
                const allComments = response.data.comments || [];

                if (!allItems.length) {
                    $('#progress').append('<br>⚠ No posts/pages found.');
                    isImporting = false;
                    return;
                }

                const posts = allItems.filter(item => item.post_type === 'post');
                const pages = allItems.filter(item => item.post_type === 'page');

                $('#progress').append('<br>✅ Found: ' + posts.length + ' posts and ' + pages.length + ' pages');
                $('#progress').append('<br>✅ Found: ' + allComments.length + ' comments');

                if (posts.length) {
                    importNext(0, posts, function() {
                        if (pages.length) {
                            $('#progress').append('<br>📦 Now importing pages...');
                            importNext(0, pages, function() {
                                $('#progress').append('<br>🎉 All posts & pages imported!');
                                isImporting = false;
                                $('#importOverlay').hide();
                            });
                        } else {
                            $('#progress').append('<br>🎉 All posts imported!');
                            isImporting = false;
                            $('#importOverlay').hide();
                        }
                    });
                } else if (pages.length) {
                    $('#progress').append('<br>📦 Only pages to import...');
                    importNext(0, pages, function() {
                        $('#progress').append('<br>🎉 All pages imported!');
                        isImporting = false;
                        $('#importOverlay').hide();
                    });
                } else {
                    $('#progress').append('<br>⚠ Nothing to import.');
                    isImporting = false;
                    $('#importOverlay').hide();
                }
                if (allComments.length)  {
                    $('#progress').append('<br>' + allComments.length + ' Comments to import if they match id.');
                    allComments.forEach(comment => {
                        $('#progress').append('<br>  parent_id = ' + comment.blogger_parent_id);
                    })
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
        $('#progress').append('<hr>');
        $('#progress').append('<br>📄 Importing ' + escapeHtml(post.post_type) + ': ' + escapeHtml(post.title));
        $('#progress').append('<br>   blogger id: ' + escapeHtml(post.blogger_id));
        scrollToBottom();

        $.post(btwImporter.ajaxUrl, {
            action: 'btw_import_single_post',
            nonce: btwImporter.nonce,
            post: post
        }, function(response) {
            if (response.success && Array.isArray(response.data)) {
                response.data.forEach(msg => {
                    let cleanMsg = escapeHtml(msg);
                    if (msg.includes('Created category') || msg.includes('Using category')) {
                        $('#progress').append('<br>🏷 ' + cleanMsg);
                    } else if (msg.includes('Finished create 301 redirect')) {
                        $('#progress').append('<br>🔁 ' + cleanMsg);
                    } else {
                        $('#progress').append('<br>' + cleanMsg);
                    }
                });
                $('#progress').append('<br>----------------------------------------');
            } else {
                $('#progress').append('<br>❌ Failed: ' + escapeHtml(response.data));
            }
            scrollToBottom();
            importNext(index + 1, items, doneCallback);
        }).fail(function(xhr, status, error) {
            $('#progress').append('<br>❌ AJAX error: ' + escapeHtml(error));
            scrollToBottom();
            importNext(index + 1, items, doneCallback); // continue anyway
        });
    }

    function scrollToBottom() {
        const progress = $('#progress');
        progress.scrollTop(progress[0].scrollHeight);
    }

    function escapeHtml(text) {
        return $('<div>').text(text).html();
    }

    // Warn user before leaving if import is running
    window.addEventListener('beforeunload', function(e) {
        if (isImporting) {
            e.preventDefault();
            e.returnValue = 'Are you sure want to stop the import proccess?'; // standard way to show confirm dialog
        }
    });
});
