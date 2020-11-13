let flags = {
	debug: false,
	working: false,
	handled: true
};
let icdcs = {
	_asyncFuncConstructor: Object.getPrototypeOf(async function(){}).constructor,
	query: {
		command: "next",
		scripts: [
			{
				filename: "other/example",
				cursorPosition: 0
			}
		],
		customVars: {
			test: true
		}
	},
	response: {},
	request: function(command = this.query.command){
		if (flags.handled){
			try {
				this.query.command = command;

				var xmlhttp;
				if (window.XMLHttpRequest) {
					xmlhttp = new XMLHttpRequest();
				}
				else {
					alert("Браузер не поддерживается");
				}

				xmlhttp.open("POST", "icdcs.php", true);
				xmlhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
				xmlhttp.onreadystatechange = function() {
					if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
						try {
							icdcs.response = JSON.parse(xmlhttp.response);
							if ("scripts" in icdcs.response){
								icdcs.query.scripts = icdcs.response.scripts;
							}
							icdcs.handleResponse();
						} 
						catch (err) {
							err = "Ошибка обработки ответа сервера: " + err;
							alert(err);
							console.error(err);
						}
					}
				}
				xmlhttp.send("jsonQuery="+JSON.stringify(this.query));
			}
			catch (err) {
				err = "Ошибка при отправки данных на сервер: " + err;
				alert(err);
				console.error(err);
			}
		}
	},
	handleResponse: async function(){
		flags.handled = false;
		if ("error" in this.response){
			console.error(this.response.error);
		}
		if ("expressions" in this.response){
			for (var i = 0; i < this.response.expressions.length; i++){
				try {
					var expression = new this._asyncFuncConstructor(this.response.expressions[i].js);
					if ("async" in this.response.expressions[i]){
						expression();
					}
					else {
						await expression();
					}
				}
				catch (err) {
					console.warn("Ошибка при обработке выражений ответа: " + err);
				}
			}
		}
		else {
			console.warn("Не обнаружен \"expressions\" в ответе");
		}
		if ("warnings" in this.response){
			for (var i = 0; i < this.response.warnings.length; i ++){
				console.warn(this.response.warnings[i]);
			}
		}
		flags.handled = true;
	}
};