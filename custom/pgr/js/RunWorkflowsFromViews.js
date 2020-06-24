
function btnRunWorkflows (sourceObject, itemModule, itemId) {
    //debugger;
    //var urlVars = getUrlVars(location.href);
    var theModule;
    var RuntimeArgs = InitRuntimeArgs();

    var ids;
    var startTime = new Date();

    if (typeof itemModule === 'undefined') { // not a subpanel item, use top level module and id(s)
        theModule = RuntimeArgs['module'];
        if (RuntimeArgs['controllerAction'] === 'listview') {
            sugarListView.get_checks();
            if (sugarListView.get_checks_count() < 1) {
                return false;
            }
            ids = document.MassUpdate.uid.value;
        } else if (RuntimeArgs['controllerAction'] === 'DetailView') {
            ids = RuntimeArgs['record'];
        } else {
            return;
        }
    } else { // subpanel item, use module and id from parameters
        if (typeof itemId === 'undefined') {
            return;
        }
        theModule = itemModule.charAt(0).toUpperCase() + itemModule.slice(1); // like PHP's ucfirst()
        ids = itemId;
    }

    $.ajaxSetup({ async: false });

    var report = $.getJSON('index.php', {
        module: 'Home',
        action: 'RunWorkflowsFromViews',
        current_module: theModule,
        uids: ids,
    });
    $.ajaxSetup({ async: true });
    var timeDiff = ((new Date() - startTime) / 1000).toFixed(1) ;
    //alert(JSON.stringify(report.responseText));
    //var reportText = report.responseText.replace(/[{}"]/g, '').replace(/,/g, '&#10;');
    var reportText =
        'Applicable Workflows:  ' + report.responseJSON[0] +
        '&#10;Records examined: ' + report.responseJSON[1] +
        '&#10;Records executed: ' + report.responseJSON[2] +
        '&#10;Seconds elapsed:  ' + timeDiff;
    sourceObject.innerHTML = '<div title="' + reportText + '">' + sourceObject.innerHTML + ' &#x2713;</div>';
//debugger;

    return false;
}

function getUrlVars(url) {
    var a = document.createElement('a');
    a.href = 'http://' + url;
    var path = decodeURIComponent(a.hash);

    var vars = {};
    var parts = path.replace(/[?&]+([^=&]+)=([^&]*)/gi, function(m,key,value) {
        vars[key] = value;
    });
    //alert(JSON.stringify(vars));
    //alert(vars['module']);
    return vars;
}

$(document).ready(function() {
    InitRuntimeArgs();

    var action4ListTop = $('<li><a href="javascript:void(0)" onclick="btnRunWorkflows(this)">Run Workflows</a></li>")');
    var action4ListBottom = action4ListTop.clone();
    var action4SubPanels = action4ListTop.clone();
    var action4DetailDropdown = $('<li><input type="button" class="button" onclick="btnRunWorkflows(this); return false;" value="Run Workflows"></li>")');
    var action4DetailButton = $('<button    type="button" class="button" onclick="btnRunWorkflows(this); return false;" value="RunWorkflows">Run Workflows</button>');

    // Add item to action dropdown for selected items on list view
    $("#actionLinkTop").sugarActionMenu('addItem',{item:action4ListTop});
    $("#actionLinkBottom").sugarActionMenu('addItem',{item:action4ListBottom});

    // if Detail views have Actions spread out as buttons, not in a dropdown:
    var btnActions = $("#formDetailView .buttons input");
    if (btnActions.length) {
        btnActions.last().before(action4DetailButton);
    }
    // if Detail views have Actions in a dropdown:
    var tabActions = $("#tab-actions .dropdown-menu li");
    if (tabActions.length) {
        tabActions.last().after(action4DetailDropdown);
    }

    // Add item to action dropdown for select items for all subpanels on detail view
    $("#subpanel_list ul.clickMenu").each(function(){
        var itemId = $(this).attr('id');
        var itemModule = $(this).closest('[id*="list_subpanel_"]').attr('id').replace('list_subpanel_','');
        if ((itemModule === 'activities') || (itemModule === 'history')) {
            // further guess-work is required to get the correct module from these aggregated subpanels:
            var pos;
            var hint = $(this).find('a').prop('outerHTML');
            if (hint.match('sub_p_rem')) {
                pos = 1;
            }
            if (hint.match('subp_nav') || hint.match('closeActivityPanel.show')) {
                pos = 0;
            }
            itemModule = hint.split('&quot;').join('\'').match(/'([^']*)'/g)[pos].replace(/['"]+/g, '');
        }
        //debugger;
        // clones the new button, adds it at the end of the UL list, and tweaks the onclick code to pass extra parameters.
        $(this).find('ul').append(action4SubPanels.clone().children().first()
            .attr('onClick', "btnRunWorkflows(this, \'" + itemModule + "\', \'" + itemId + "\')")
            .addClass('action4SubPanels')
        );
    } );
});