<?php
/** Deterministic institutional evidence checks shared by registration and activation. */
final class InstitutionalEligibility
{
    public static function normalizeName(string $value): string {
        return mb_strtolower(trim(preg_replace('/\s+/u',' ',$value)));
    }
    public static function reason(string $code): string {
        return match($code) {
            'eligible'=>'Trusted institutional identity and role matched.',
            'restricted_role'=>'This role requires restricted administrator provisioning.',
            'assessment_blocked'=>'The registration has a rejected or high-risk assessment requiring authorized investigation.',
            'profile_missing'=>'The account has no unique institutional role profile or identifier.',
            'source_unavailable'=>'The trusted institutional roster schema is unavailable. The deployment needs the account-monitoring migration.',
            'roster_missing'=>'No matching identifier exists in the trusted institutional roster. Authorized enrollment or staff records must be synchronized.',
            'roster_ambiguous'=>'Multiple trusted roster entries match this identifier. The source records need correction.',
            'roster_ineligible'=>'The trusted institutional record does not confirm current enrollment or employment eligibility.',
            'role_mismatch'=>'The requested role does not match the trusted institutional record.',
            'name_mismatch'=>'The registered name does not match the trusted institutional record.',
            'email_mismatch'=>'The verified email does not match the trusted institutional record.',
            'license_mismatch'=>'The nursing license does not match the trusted staff record.',
            'identifier_claimed'=>'The institutional identifier is already linked to another account.',
            default=>'Institutional identity or role eligibility still requires verification.'
        };
    }
    public static function result(string $code): array {
        return ['eligible'=>$code==='eligible','code'=>$code,'reason'=>self::reason($code)];
    }
    public static function roster(PDO $db,string $identifier): array {
        try {
            $q=$db->prepare('SELECT * FROM institutional_roster WHERE identifier=?');
            $q->execute([trim($identifier)]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            // An absent/outdated eligibility schema is a restricted state, never implicit approval.
            if(in_array((string)$e->getCode(),['42S02','42S22'],true))return self::result('source_unavailable');
            throw $e;
        }
        if(count($rows)!==1)return self::result($rows?'roster_ambiguous':'roster_missing');
        if(array_diff(['first_name','last_name','role','institutional_email','status'],array_keys($rows[0])))return self::result('source_unavailable');
        return ['record'=>$rows[0]];
    }
    public static function matchIdentity(array $record,array $identity,string $license=''): array {
        if(!in_array($record['status'],['eligible','registered'],true))return self::result('roster_ineligible');
        if($record['role']!==$identity['role'])return self::result('role_mismatch');
        if(!filter_var(trim($identity['email']),FILTER_VALIDATE_EMAIL) || strcasecmp(trim($record['institutional_email']),trim($identity['email']))!==0)return self::result('email_mismatch');
        foreach(['first_name','last_name'] as $field) {
            if(self::normalizeName($identity[$field])==='' || self::normalizeName($record[$field])!==self::normalizeName($identity[$field]))return self::result('name_mismatch');
        }
        if($identity['role']==='nurse' && !array_key_exists('license_number',$record))return self::result('source_unavailable');
        if($identity['role']==='nurse' && (!$license || empty($record['license_number']) || strcasecmp(trim($record['license_number']),trim($license))!==0))return self::result('license_mismatch');
        return self::result('eligible');
    }
}
