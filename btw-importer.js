jQuery(document).ready(function($){
    $('#startImport').click(function(){
        var f=$('#atomFile')[0].files[0];
        if(!f){ alert('Select file');return;}
        var r=new FileReader();
        r.onload=function(e){
            $('#progress').append('<div>📦 Parsing...</div>');
            $.post(btwImporter.ajaxUrl,{action:'btw_prepare_import',nonce:btwImporter.nonce,atom_content:e.target.result},function(res){
                if(res.success){
                    var p=res.data.posts,i=0;
                    $('#progress').append('<div>✅ Found '+p.length+' posts</div>');
                    function next(){
                        if(i>=p.length){ $('#progress').append('<div>🎉 Done!</div>'); return; }
                        $.post(btwImporter.ajaxUrl,{action:'btw_import_single_post',nonce:btwImporter.nonce,post:p[i]},function(r){
                            if(r.success) r.data.forEach(m=>$('#progress').append('<div>'+m+'</div>'));
                            else $('#progress').append('<div>❌ '+r.data+'</div>');
                            i++; next();
                        });
                    }
                    next();
                } else $('#progress').append('<div>❌ '+res.data+'</div>');
            });
        }; r.readAsText(f);
    });
});
