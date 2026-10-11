/*
 * Avatar base64 guard — encodes the picked file into "avatar_b64" as soon
 * as it is selected (async work must finish before submit), and disables
 * the file input on submit so exactly one source ships. Shared hosting
 * multipart staging can be broken (upload_tmp_dir) or capped
 * (upload_max_filesize); the urlencoded base64 field bypasses $_FILES
 * staging. Works for both native submits and htmx (FormData snapshots run
 * after this handler and skip the disabled input). Without JS the plain
 * multipart upload is sent as before.
 */
(function(){
    var input=document.getElementById('avatar');
    if(!input||!input.form)return;
    var form=input.form;
    var b64=document.createElement('input');
    b64.type='hidden';
    b64.name='avatar_b64';
    var name=document.createElement('input');
    name.type='hidden';
    name.name='avatar_b64_name';
    form.appendChild(b64);
    form.appendChild(name);
    input.addEventListener('change',function(){
        b64.value='';
        name.value='';
        var file=input.files&&input.files[0];
        if(!file||typeof FileReader==='undefined')return;
        var reader=new FileReader();
        reader.onload=function(){
            if(input.files&&input.files[0]===file){
                b64.value=String(reader.result).split(',').pop()||'';
                name.value=file.name;
            }
        };
        reader.readAsDataURL(file);
    });
    form.addEventListener('submit',function(){
        if(b64.value){input.disabled=true;}
    });
})();
