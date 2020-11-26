var  processinfo = document.getElementById('process-info');
var  dccconsole = document.getElementById('dccconsole');

var maxlines = 10;

setInterval(function(){
    result = $.ajax({
        type: "POST",
        async: false,
        url: "control.php",
        data: ({
            ctl_action: "check"
        })
    }).responseText;
    if (result != '') {
        check = JSON.parse(result);
        if (check.process_running == 'true') {
            processinfo.innerHTML = 'Process is running';
            processinfo.style.color = 'green';
            //linebreak = '&#13;&#10;';
            linebreak = '\n';
            if (dccconsole.value == '') {
                linebreak = '';
            }
            totalcontent = '';
            check.lastlogs.forEach(function(lastlog){
                totalcontent += lastlog;
            });
            totallength = totalcontent.split('\n').length;
            if (totallength > maxlines) {
                // cut
                var diff = totallength - maxlines;
                totalcontentarray = totalcontent.split(linebreak);
                totalcontentarray = totalcontentarray.slice(diff);
                dccconsole.innerHTML = totalcontentarray.join(linebreak);
            }
            else {
                dccconsole.innerHTML = totalcontent;
            }
            dccconsole.scrollTop = dccconsole.scrollHeight;
        } else if (check.process_running == 'false') {
            processinfo.innerHTML = 'Process is not running';
            processinfo.style.color = 'red';
        }
    }
}, 5000);