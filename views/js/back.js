/**
 * Module Mjfakturownia
 * @author MAGES Michał Jendraszczyk
 * @copyright (c) 2020, MAGES Michał Jendraszczyk
 * @license http://mages.pl MAGES Michał Jendraszczyk
 */

$(document).ready(function(){

	if (getUrlParameter('controller') == 'AdminOrders')
	{
		pslAddToolbarFakturowniaBtn();
	}

	$(document.body).on('click', '#fakturownia_pl', function(){

		 orders_ids = [];

		if (typeof( getUrlParameter('id_order') ) == 'undefined')
		{
			$("input:checkbox.noborder").each(function(){

				if ( $(this).is(":checked") == true && $.isNumeric( $(this).val() ) )
				{
					//orders_ids = $.extend({}, $(this).val() );
					orders_ids.push( $(this).val() );
				}
			});

			if (orders_ids.length === 0) {
				alert(l('You have to check at least an order!'));
				return;
			}

		}
		else
		{
			orders_ids.push( getUrlParameter('id_order') );
		}

		var orders_ids_ser='';

		var getLocation = function(href) {
		    var l = document.createElement("a");
		    l.href = href;
		    return l;
		};

		var siteHost = getLocation(window.location.href);
		var doneo = 0;
		var notdoneo = 0;

		for (i=0; i < orders_ids.length; i++) {
			$('#progressBar').css('display', 'block');
			$.ajax({
				url: "http://" + siteHost.hostname + module_path + "issue_invoice.php?id_order=" + orders_ids[i] + "&kind=vat",
				success: function() {
					doneo++;
					var progressBarWidth = ((doneo+notdoneo)/orders_ids.length)*100 * $('#progressBar').width() / 100;
					$('#progressBar').find('div').animate({ width: progressBarWidth }, 500).html(((doneo+notdoneo)/orders_ids.length)*100 + "% ");
					if ((notdoneo+doneo) == orders_ids.length) {
						alert2(doneo, orders_ids, 'Wygenerowano ' + doneo + ' faktur z ' + orders_ids.length + ' zaznaczonych. Czy chcesz je zapisać?', 'Ukończono generowanie faktur', 'Ok');
					}
			    },

			    error: function() {
					notdoneo++;
					var progressBarWidth = ((doneo+notdoneo)/orders_ids.length)*100 * $('#progressBar').width() / 100;
					$('#progressBar').find('div').animate({ width: progressBarWidth }, 500).html(((doneo+notdoneo)/orders_ids.length)*100 + "% ");
					if ((notdoneo+doneo) == orders_ids.length) {
						alert2(doneo, orders_ids, 'Wygenerowano ' + doneo + ' faktur z ' + orders_ids.length + ' zaznaczonych. Czy chcesz je zapisać?', 'Ukończono generowanie faktur', 'Ok');
					}
			    }
			});
		}
	});

});

function alert2(doneo, orders_ids, message, title, buttonText) {

    buttonText = (buttonText == undefined) ? "Ok" : buttonText;
    title = (title == undefined) ? "The page says:" : title;

    var div = $('<div>');
    div.html(message);
    div.attr('title', title);

    var getLocation = function(href) {
	    var l = document.createElement("a");
	    l.href = href;
	    return l;
	};
	var siteHost = getLocation(window.location.href);
	div.append("</br>");
    for (i=0; i < orders_ids.length; i++) {
		$.ajax({
			url: "http://" + siteHost.hostname + module_path + "issue_invoice.php?id_order=" + orders_ids[i] + "&kind=vat&invoice=1",
			success: function(data) {
				div.append("</br><a target='_blank' href='" + data + "'>" + data + "</a>");
		    }
		});
	}

    div.dialog({
        autoOpen: true,
        modal: true,
        draggable: false,
        resizable: false,
        width: "80%",
        left: "10%",
        buttons: [{
            text: buttonText,
            click: function () {
            	var getLocation = function(href) {
				    var l = document.createElement("a");
				    l.href = href;
				    return l;
				};
            	var siteHost = getLocation(window.location.href);

                $(this).dialog("close");

                $('#progressBar').css('display', 'none');

                var added = 0;

                /*$('a', div).each(function() {
                	window.open($(this).attr('href') );
                	alert($(this).attr('href'));
                });*/

                for (i=0; i < orders_ids.length; i++) {
					$.ajax({
						url: "http://" + siteHost.hostname + module_path + "issue_invoice.php?id_order=" + orders_ids[i] + "&kind=vat&invoice=1",
						success: function(data) {
							div.append("<a target='_blank' href='" + data + "'>" + data + "</a>");
							added++;
							if (added == doneo) {
								recursiveAjax2(siteHost, 0, orders_ids);
							}
					    }
					});
				}

//                recursiveAjax(siteHost, 0, orders_ids);
                /*for (i=0; i < orders_ids.length; i++) {
					$('#progressBar').append("<a href='" + "'>" + i + "</a>");
					$.ajax({
						url: "http://" + siteHost.hostname + module_path + "issue_invoice.php?id_order=" + orders_ids[i] + "&kind=vat&invoice=1",
						success: function(data) {
							window.location.href = data;
						}
					});
				}*/
                div.remove();
            }
        }]
    });
}

function recursiveAjax2() {
	for (i=0; i < orders_ids.length; i++) {
		$('#progressBar a').each(function() {
			window.location = $(this).attr('href');
		});
	}
}

function recursiveAjax(siteHost, index, orders_ids) {
	$.ajax({
		url: "http://" + siteHost.hostname + module_path + "issue_invoice.php?id_order=" + orders_ids[index++] + "&kind=vat&invoice=1",
		success: function(data) {
			window.open(
				data,
				'_blank' // <- This is what makes it open in a new window.
			);
			if (index < orders_ids.length)
				recursiveAjax(siteHost, index, orders_ids);
			return;
		}
	});
}



function createCORSRequest(method, url) {
  var xhr = new XMLHttpRequest();
  if ("withCredentials" in xhr) {
    // XHR for Chrome/Firefox/Opera/Safari.
    xhr.open(method, url, true);
  } else if (typeof XDomainRequest != "undefined") {
    // XDomainRequest for IE.
    xhr = new XDomainRequest();
    xhr.open(method, url);
  } else {
    // CORS not supported.
    xhr = null;
  }
  return xhr;
}

// Make the actual CORS request.
function makeCorsRequest(url) {
  var xhr = createCORSRequest('GET', url);
  if (!xhr) {
    alert('CORS not supported');
    return;
  }

  xhr.send();
}
function getUrlParameter(sParam) {
    var sPageURL = window.location.search.substring(1),
        sURLVariables = sPageURL.split('&'),
        sParameterName,
        i;

    for (i = 0; i < sURLVariables.length; i++) {
        sParameterName = sURLVariables[i].split('=');

        var key = decodeURIComponent(sParameterName[0]);
        var value = decodeURIComponent(sParameterName[1]);

        if (key === sParam) {
            return value === undefined ? true : value;
        }
    }
};