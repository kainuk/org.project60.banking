(function(angular, $, _) {

  angular.module('banking').config(function($routeProvider) {
      $routeProvider.when('/banking/rules', {
        controller: 'BankingRules',
        templateUrl: '~/banking/Rules.html',

        // If you need to look up data when opening the page, list it out
        // under "resolve".
        resolve: {
          myContact: function(crmApi) {
            return crmApi('Contact', 'getsingle', {
              id: 'user_contact_id',
              return: ['first_name', 'last_name']
            });
          }
        }
      });
      $routeProvider.when('/banking/rules/:rule_id', {
        controller: 'BankingRule',
        templateUrl: '~/banking/Rule.html',
        resolve: {
          rule_data: function(crmApi, $route) {
            return crmApi('BankingRule', 'getruledata', {
              id: $route.current.params.rule_id
            }).then(function(result) { return result.values; });
          }
        }
      });
    }
  );

  // The controller uses *injection*. This default injects a few things:
  //   $scope -- This is the set of variables shared between JS and HTML.
  //   crmApi, crmStatus, crmUiHelp -- These are services provided by civicrm-core.
  //   myContact -- The current contact, defined above in config().
  angular.module('banking').controller('BankingRules', function($scope, crmApi, crmStatus, crmUiHelp, myContact) {
    // The ts() and hs() functions help load strings for this module.
    var ts = $scope.ts = CRM.ts('banking');
    var hs = $scope.hs = crmUiHelp({file: 'ang/banking/Rules'}); // See: templates/CRM/banking/Rules.hlp

    // We have myContact available in JS. We also want to reference it in HTML.
    $scope.myContact = myContact;

    $scope.save = function save() {
      return crmStatus(
        // Status messages. For defaults, just use "{}"
        {start: ts('Saving...'), success: ts('Saved')},
        // The save action. Note that crmApi() returns a promise.
        crmApi('Contact', 'create', {
          id: myContact.id,
          first_name: myContact.first_name,
          last_name: myContact.last_name
        })
      );
    };
  });

})(angular, CRM.$, CRM._);